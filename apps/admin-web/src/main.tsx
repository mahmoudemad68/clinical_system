import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter } from 'react-router-dom';
import { App } from '@/app/App';
import { createAdminQueryClient } from '@/app/queryClient';
import i18n, { applyDocumentDirection } from '@/i18n';
import { setClientLocale } from '@/api/locale';

const queryClient = createAdminQueryClient();

applyDocumentDirection(i18n.resolvedLanguage ?? 'en');
setClientLocale(i18n.resolvedLanguage ?? 'en');

const container = document.getElementById('root');

if (!container) {
  throw new Error('Root container missing from index.html');
}

createRoot(container).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
);
