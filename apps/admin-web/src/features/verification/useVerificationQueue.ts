import { useQuery } from '@tanstack/react-query';
import { ApiError, apiClient, toApiFailure } from '@/api/client';
import {
  isDoctorVerificationQueueItem,
  isPharmacyVerificationQueueItem,
} from '@/features/verification/doctorVerification';
import {
  verificationKeys,
  type QueueAssignmentFilter,
  type VerificationCaseTypeFilter,
} from '@/session/keys';
import { useSession } from '@/session/useSession';

export function useVerificationQueue(
  caseType: VerificationCaseTypeFilter,
  assignment: QueueAssignmentFilter,
  cursor: string | null,
) {
  const session = useSession();

  return useQuery({
    queryKey: verificationKeys.queue(caseType, assignment, cursor),
    enabled: session.canReviewVerification,
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
      const { data, error, response } = await apiClient.GET('/api/v1/admin/verification-cases', {
        params: {
          query: {
            assignment,
            case_type: caseType,
            status: 'pending_review',
            ...(cursor !== null ? { cursor } : {}),
          },
        },
      });

      if (error || !data.data) {
        throw new ApiError(toApiFailure(error, response.status));
      }

      const items =
        caseType === 'pharmacy_verification'
          ? data.data.filter(isPharmacyVerificationQueueItem)
          : data.data.filter(isDoctorVerificationQueueItem);

      return {
        items,
        pagination: data.meta.pagination,
        requestId: data.request_id,
      };
    },
  });
}
