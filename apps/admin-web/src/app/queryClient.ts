import { QueryClient } from '@tanstack/react-query';
import { ApiError } from '@/api/client';

export function createAdminQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        refetchOnWindowFocus: true,
        staleTime: 10_000,
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
      },
      mutations: {
        retry: false,
      },
    },
  });
}
