import { useQuery } from '@tanstack/react-query';
import { apiClient, toApiFailure, ApiError } from '@/api/client';

/**
 * Server state for the platform health panel.
 *
 * TanStack Query owns server state (phase file, "React baseline"); component
 * state stays local. Retry is bounded and does not apply to a 4xx: retrying a
 * request the server has already refused adds load and never succeeds.
 */
export function usePlatformHealth() {
  return useQuery({
    queryKey: ['platform', 'health'],
    queryFn: async () => {
      const { data, error, response } = await apiClient.GET('/api/v1/health');

      if (error || !data.data) {
        // Narrowing here rather than in the component: the envelope's `data`
        // is optional in the generated types because an error envelope has
        // none. Resolving that once, at the transport edge, keeps every
        // consumer free of undefined checks the server will never actually
        // produce on a 200.
        throw new ApiError(toApiFailure(error, response.status));
      }

      return data.data;
    },
    // Health is cheap and changes on its own; poll rather than make an operator
    // reload the page to notice an outage.
    refetchInterval: 30_000,
    staleTime: 15_000,
    retry: (failureCount, error) => {
      const status = error instanceof ApiError ? error.failure.status : 0;

      // Never retry a client error; the server already decided.
      if (status >= 400 && status < 500) {
        return false;
      }

      return failureCount < 2;
    },
  });
}
