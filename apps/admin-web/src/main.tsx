import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from '@/app/App';
import i18n, { applyDocumentDirection } from '@/i18n';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Server state is authoritative and cheap to refetch. Refetching on focus
      // means an operator who leaves a tab open does not act on stale status.
      refetchOnWindowFocus: true,
      staleTime: 10_000,
    },
  },
});

applyDocumentDirection(i18n.resolvedLanguage ?? 'en');

const container = document.getElementById('root');

if (!container) {
  throw new Error('Root container missing from index.html');
}

createRoot(container).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>
  </StrictMode>,
);
