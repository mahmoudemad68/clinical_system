import { useTranslation } from 'react-i18next';
import { usePlatformHealth } from './usePlatformHealth';
import { ApiError } from '@/api/client';

type ComponentStatus = 'operational' | 'degraded' | 'unavailable';

/**
 * The Phase 00 end-to-end surface for the admin client: display core health and
 * version in Arabic and English.
 *
 * Accessibility notes, because a status panel is exactly where colour-only
 * signalling creeps in:
 *   - status is conveyed by text as well as colour;
 *   - the live region announces a change to a screen reader without a reload;
 *   - the semantic list keeps component/status pairs associated.
 */
export function HealthPanel() {
  const { t } = useTranslation();
  const { data, error, isPending } = usePlatformHealth();

  if (isPending) {
    return (
      <section aria-busy="true" aria-live="polite">
        <h2>{t('health.title')}</h2>
        <p>{t('health.loading')}</p>
      </section>
    );
  }

  if (error) {
    const failure = error instanceof ApiError ? error.failure : undefined;

    return (
      <section aria-live="assertive">
        <h2>{t('health.title')}</h2>
        <p role="alert">{failure?.message ?? t('health.unreachable')}</p>
        {failure?.requestId ? (
          <p>
            <span>{t('health.requestId')}: </span>
            <code>{failure.requestId}</code>
          </p>
        ) : null}
      </section>
    );
  }

  const components = data.components as Record<string, ComponentStatus>;

  return (
    <section aria-live="polite">
      <h2>{t('health.title')}</h2>

      <p data-testid="overall-status">
        {t(`health.status.${data.status}`)}
      </p>
      <p>{data.message}</p>

      <dl>
        {(['core', 'realtime', 'ai'] as const).map((key) => (
          <div key={key}>
            <dt>{t(`health.components.${key}`)}</dt>
            <dd data-testid={`component-${key}`}>
              {/* Text, not just colour. */}
              {t(`health.status.${components[key] ?? 'unavailable'}`)}
            </dd>
          </div>
        ))}
      </dl>

      <dl>
        <div>
          <dt>{t('health.version')}</dt>
          <dd data-testid="version">{data.version}</dd>
        </div>
        <div>
          <dt>{t('health.serverTime')}</dt>
          <dd>
            {/* The server sends UTC; the browser renders it in local time. */}
            <time dateTime={data.server_time}>
              {new Date(data.server_time).toLocaleString()}
            </time>
          </dd>
        </div>
      </dl>
    </section>
  );
}
