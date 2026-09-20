import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import type { components } from '@clinic/api-client/schema';
import { ApiError, apiClient, isAuthError, toApiFailure } from '@/api/client';
import { revokeAllBlobUrls } from '@/api/blobUrls';
import { setClientLocale } from '@/api/locale';
import i18n from '@/i18n';
import {
  sessionKeys,
  verificationKeys,
  VERIFICATION_REVIEW_CAPABILITY,
  type SessionStatus,
} from '@/session/keys';
import { SessionContext, type SessionValue } from '@/session/sessionContext';

type MeResult = components['schemas']['MeResult'];
type CapabilitiesResult = components['schemas']['CapabilitiesResult'];

async function fetchMe(): Promise<MeResult | null> {
  const { data, error, response } = await apiClient.GET('/api/v1/me');

  if (response.status === 401) {
    return null;
  }

  if (error || !data.data) {
    throw new ApiError(toApiFailure(error, response.status));
  }

  return data.data;
}

async function fetchCapabilities(): Promise<CapabilitiesResult> {
  const { data, error, response } = await apiClient.GET('/api/v1/me/capabilities');

  if (error || !data.data) {
    throw new ApiError(toApiFailure(error, response.status));
  }

  return data.data;
}

function deriveStatus(
  mePending: boolean,
  me: MeResult | null | undefined,
  meError: unknown,
  capsPending: boolean,
  capabilities: readonly string[] | undefined,
  capsError: unknown,
  expired: boolean,
): SessionStatus {
  if (expired) {
    return 'session_expired';
  }

  if (mePending) {
    return 'bootstrapping';
  }

  if (isAuthError(meError) || me === null) {
    return 'signed_out';
  }

  if (meError) {
    return 'signed_out';
  }

  if (me === undefined) {
    return 'bootstrapping';
  }

  if (capsPending) {
    return 'bootstrapping';
  }

  if (isAuthError(capsError)) {
    return 'session_expired';
  }

  if (capsError || capabilities === undefined) {
    return 'unauthorized';
  }

  return capabilities.includes(VERIFICATION_REVIEW_CAPABILITY)
    ? 'authorized_reviewer'
    : 'unauthorized';
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [expired, setExpired] = useState(false);
  const [signedOutLocally, setSignedOutLocally] = useState(false);
  const appliedLanguage = useRef(false);

  const meQuery = useQuery({
    queryKey: sessionKeys.me,
    queryFn: fetchMe,
    enabled: !signedOutLocally,
    retry: false,
    staleTime: 15_000,
    refetchOnWindowFocus: true,
  });

  const authenticated =
    !signedOutLocally && meQuery.data !== null && meQuery.data !== undefined && !meQuery.error;

  const capsQuery = useQuery({
    queryKey: sessionKeys.capabilities,
    queryFn: fetchCapabilities,
    enabled: authenticated,
    retry: false,
    staleTime: 15_000,
    refetchOnWindowFocus: true,
  });

  const status = signedOutLocally
    ? 'signed_out'
    : deriveStatus(
        meQuery.isPending,
        meQuery.data,
        meQuery.error,
        capsQuery.isPending && authenticated,
        capsQuery.data?.capabilities,
        capsQuery.error,
        expired,
      );

  useEffect(() => {
    if (appliedLanguage.current) {
      return;
    }
    const language = meQuery.data?.language;
    if (language === 'ar' || language === 'en') {
      appliedLanguage.current = true;
      setClientLocale(language);
      if (i18n.resolvedLanguage !== language) {
        void i18n.changeLanguage(language);
      }
    }
  }, [meQuery.data?.language]);

  const expireSession = useCallback(() => {
    revokeAllBlobUrls();
    queryClient.removeQueries({ queryKey: verificationKeys.all });
    queryClient.removeQueries({ queryKey: sessionKeys.all });
    setExpired(true);
  }, [queryClient]);

  useEffect(() => {
    const onQueryError = (error: unknown): void => {
      if (isAuthError(error) && status === 'authorized_reviewer') {
        expireSession();
      }
    };

    const unsubQuery = queryClient.getQueryCache().subscribe((event) => {
      if (event.type === 'updated' && event.query.state.status === 'error') {
        onQueryError(event.query.state.error);
      }
    });
    const unsubMutation = queryClient.getMutationCache().subscribe((event) => {
      if (event.type === 'updated' && event.mutation.state.status === 'error') {
        onQueryError(event.mutation.state.error);
      }
    });

    return () => {
      unsubQuery();
      unsubMutation();
    };
  }, [expireSession, queryClient, status]);

  const refresh = useCallback(async () => {
    setExpired(false);
    setSignedOutLocally(false);
    await queryClient.invalidateQueries({ queryKey: sessionKeys.all });
    await queryClient.refetchQueries({ queryKey: sessionKeys.me });
    await queryClient.refetchQueries({ queryKey: sessionKeys.capabilities });
  }, [queryClient]);

  const logout = useCallback(async () => {
    try {
      await apiClient.POST('/api/v1/auth/logout', {});
    } catch {
      // Server logout is best-effort; local sensitive state still clears.
    }
    revokeAllBlobUrls();
    appliedLanguage.current = false;
    setExpired(false);
    setSignedOutLocally(true);
    queryClient.clear();
  }, [queryClient]);

  const value = useMemo<SessionValue>(
    () => ({
      status,
      me: meQuery.data ?? null,
      capabilities: capsQuery.data?.capabilities ?? [],
      canReviewVerification: status === 'authorized_reviewer',
      refresh,
      logout,
      expireSession,
    }),
    [capsQuery.data?.capabilities, expireSession, logout, meQuery.data, refresh, status],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}
