import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiClient, toApiFailure } from '@/api/client';
import { isDoctorVerificationCase } from '@/features/verification/doctorVerification';
import { verificationKeys } from '@/session/keys';
import { useSession } from '@/session/useSession';
import type { DecisionPayload } from '@/features/verification/idempotency';

function requireDoctorVerificationCase<T>(data: T): T & { case_type: 'doctor_verification' } {
  if (!isDoctorVerificationCase(data as Parameters<typeof isDoctorVerificationCase>[0])) {
    throw new ApiError({
      code: 'NOT_FOUND',
      message: 'The requested record is not available.',
      status: 404,
    });
  }

  return data as T & { case_type: 'doctor_verification' };
}

export function useVerificationCase(caseId: string | undefined) {
  const session = useSession();

  return useQuery({
    queryKey: caseId ? verificationKeys.case(caseId) : verificationKeys.caseRoot,
    enabled: session.canReviewVerification && typeof caseId === 'string' && caseId !== '',
    staleTime: 8_000,
    refetchOnWindowFocus: true,
    retry: (failureCount, error) => {
      if (!(error instanceof ApiError)) {
        return false;
      }
      const status = error.failure.status;
      if (status >= 400 && status < 500) {
        return false;
      }

      return failureCount < 1;
    },
    queryFn: async () => {
      if (caseId === undefined || caseId === '') {
        throw new ApiError({ code: 'NOT_FOUND', message: 'The requested record is not available.', status: 404 });
      }

      const { data, error, response } = await apiClient.GET(
        '/api/v1/admin/verification-cases/{case_id}',
        { params: { path: { case_id: caseId } } },
      );

      if (error || !data.data) {
        throw new ApiError(toApiFailure(error, response.status));
      }

      return requireDoctorVerificationCase(data.data);
    },
  });
}

export function useClaimVerificationCase(caseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    retry: false,
    mutationFn: async (expectedCaseVersion: number) => {
      const { data, error, response } = await apiClient.POST(
        '/api/v1/admin/verification-cases/{case_id}/claim',
        {
          params: { path: { case_id: caseId } },
          body: { expected_case_version: expectedCaseVersion },
        },
      );

      if (error || !data.data) {
        throw new ApiError(toApiFailure(error, response.status));
      }

      return requireDoctorVerificationCase(data.data);
    },
    onSuccess: async (detail) => {
      queryClient.setQueryData(verificationKeys.case(caseId), detail);
      await queryClient.invalidateQueries({ queryKey: verificationKeys.queueRoot });
      await queryClient.invalidateQueries({ queryKey: verificationKeys.case(caseId) });
    },
  });
}

export function useDecideVerificationCase(caseId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    retry: (failureCount, error) => {
      if (error instanceof ApiError && error.failure.status >= 400 && error.failure.status < 500) {
        return false;
      }

      return failureCount < 1;
    },
    mutationFn: async (input: { payload: DecisionPayload; idempotencyKey: string }) => {
      const body =
        input.payload.notes === undefined || input.payload.notes === null || input.payload.notes === ''
          ? {
              decision: input.payload.decision as 'approved' | 'rejected' | 'changes_requested',
              reason_code: input.payload.reason_code,
              expected_case_version: input.payload.expected_case_version,
            }
          : {
              decision: input.payload.decision as 'approved' | 'rejected' | 'changes_requested',
              reason_code: input.payload.reason_code,
              expected_case_version: input.payload.expected_case_version,
              notes: input.payload.notes,
            };

      const { data, error, response } = await apiClient.POST(
        '/api/v1/admin/verification-cases/{case_id}/decisions',
        {
          params: {
            path: { case_id: caseId },
            header: { 'Idempotency-Key': input.idempotencyKey },
          },
          body,
        },
      );

      if (error || !data.data) {
        throw new ApiError(toApiFailure(error, response.status));
      }

      return data.data;
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: verificationKeys.case(caseId) });
      await queryClient.invalidateQueries({ queryKey: verificationKeys.queueRoot });
    },
  });
}
