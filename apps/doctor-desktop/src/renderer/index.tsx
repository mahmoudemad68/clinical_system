import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import {
  DEFAULT_LOCALE,
  direction,
  sharedStrings,
  type Locale,
} from '@clinic/localization';
import { palette, spacing, typography } from '@clinic/design-tokens';
import type { PlatformHealth } from '@clinic/desktop-bridge-contracts';

import './clinic-bridge';

/**
 * Unprivileged renderer for the Doctor desktop.
 *
 * Note what is absent: no `fetch`, no token, no storage, no Electron import, no
 * Node import. Every interaction with the outside world is a call to
 * `window.clinic`, which is the frozen bridge the preload exposed. That is the
 * whole point of the process split — a script-execution bug here reaches the
 * five methods below and nothing else.
 */

const queryClient = new QueryClient({
  defaultOptions: { queries: { refetchOnWindowFocus: true, staleTime: 10_000 } },
});

function useLocale(): [Locale, (l: Locale) => void] {
  const [locale, setLocale] = useState<Locale>(DEFAULT_LOCALE);

  useEffect(() => {
    void window.clinic.locale.get().then((result) => {
      if (result.ok) {
        setLocale(result.value.locale);
      }
    });
  }, []);

  useEffect(() => {
    // Arabic is right-to-left; direction is a document-level concern.
    document.documentElement.setAttribute('dir', direction(locale));
    document.documentElement.setAttribute('lang', locale);
  }, [locale]);

  return [
    locale,
    (next: Locale) => {
      setLocale(next);
      // Main owns the locale so the Accept-Language header on server requests
      // matches what the user is seeing.
      void window.clinic.locale.set(next);
    },
  ];
}

function statusColour(status: PlatformHealth['status'] | 'operational'): string {
  return status === 'operational'
    ? palette.operational
    : status === 'degraded'
      ? palette.degraded
      : palette.unavailable;
}

function HealthPanel({ locale }: { locale: Locale }) {
  const t = sharedStrings[locale].health;

  const { data, error, isPending } = useQuery({
    queryKey: ['platform', 'health', locale],
    queryFn: async () => {
      const result = await window.clinic.platform.health();

      if (!result.ok) {
        throw new Error(result.error.code);
      }

      return result.value;
    },
    refetchInterval: 30_000,
    retry: 1,
  });

  if (isPending) {
    return <p aria-busy="true">{t.loading}</p>;
  }

  if (error || !data) {
    return (
      <p role="alert" style={{ color: palette.unavailable }}>
        {t.unreachable}
      </p>
    );
  }

  const components: Array<[string, PlatformHealth['status']]> = [
    [t.components.core, data.components.core],
    [t.components.realtime, data.components.realtime],
    [t.components.ai, data.components.ai],
  ];

  return (
    <section aria-live="polite">
      <p data-testid="overall-status" style={{ color: statusColour(data.status) }}>
        {t.status[data.status]}
      </p>
      <p data-testid="health-message">{data.message}</p>

      <dl>
        {components.map(([label, status]) => (
          <div key={label} style={{ display: 'flex', gap: spacing.sm }}>
            <dt style={{ minWidth: 120 }}>{label}</dt>
            {/* Text as well as colour: colour alone fails WCAG 1.4.1. */}
            <dd data-testid={`component-${label}`} style={{ color: statusColour(status) }}>
              {t.status[status]}
            </dd>
          </div>
        ))}
      </dl>

      <p>
        {t.version}: <span data-testid="version">{data.version}</span>
      </p>
      <p>
        {t.serverTime}: <time dateTime={data.serverTime}>{new Date(data.serverTime).toLocaleString()}</time>
      </p>
    </section>
  );
}

function App() {
  const [locale, setLocale] = useLocale();
  const [productName, setProductName] = useState('Clinic Doctor');

  useEffect(() => {
    void window.clinic.app.metadata().then((result) => {
      if (result.ok) {
        setProductName(result.value.productName);
      }
    });
  }, []);

  return (
    <main style={{ fontFamily: typography.fontFamily, padding: spacing.lg, maxWidth: 640 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h1 style={{ fontSize: typography.size.heading }}>{productName}</h1>

        <label>
          {sharedStrings[locale].common.language}{' '}
          <select
            aria-label={sharedStrings[locale].common.language}
            value={locale}
            onChange={(event) => setLocale(event.target.value as Locale)}
          >
            <option value="en">English</option>
            <option value="ar">العربية</option>
          </select>
        </label>
      </header>

      <h2 style={{ fontSize: typography.size.title }}>{sharedStrings[locale].health.title}</h2>
      <HealthPanel locale={locale} />
    </main>
  );
}

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
