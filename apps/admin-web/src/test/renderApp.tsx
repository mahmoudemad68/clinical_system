import { QueryClientProvider } from '@tanstack/react-query';
import { render, type RenderOptions } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { MemoryRouter } from 'react-router-dom';
import type { ReactElement, ReactNode } from 'react';
import { App } from '@/app/App';
import { createAdminQueryClient } from '@/app/queryClient';
import i18n from '@/i18n';

export function renderApp(path = '/', options?: Omit<RenderOptions, 'wrapper'>) {
  const queryClient = createAdminQueryClient();
  queryClient.setDefaultOptions({
    queries: { retry: false, staleTime: 0 },
    mutations: { retry: false },
  });

  return {
    queryClient,
    ...render(
      <QueryClientProvider client={queryClient}>
        <I18nextProvider i18n={i18n}>
          <MemoryRouter initialEntries={[path]}>
            <App />
          </MemoryRouter>
        </I18nextProvider>
      </QueryClientProvider>,
      options,
    ),
  };
}

export function renderWithProviders(ui: ReactElement, path = '/') {
  const queryClient = createAdminQueryClient();
  queryClient.setDefaultOptions({
    queries: { retry: false, staleTime: 0 },
    mutations: { retry: false },
  });

  return {
    queryClient,
    ...render(
      <QueryClientProvider client={queryClient}>
        <I18nextProvider i18n={i18n}>
          <MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>
        </I18nextProvider>
      </QueryClientProvider>,
    ),
  };
}

export function Providers({ children, path = '/' }: { children: ReactNode; path?: string }) {
  const queryClient = createAdminQueryClient();
  queryClient.setDefaultOptions({
    queries: { retry: false, staleTime: 0 },
    mutations: { retry: false },
  });

  return (
    <QueryClientProvider client={queryClient}>
      <I18nextProvider i18n={i18n}>
        <MemoryRouter initialEntries={[path]}>{children}</MemoryRouter>
      </I18nextProvider>
    </QueryClientProvider>
  );
}
