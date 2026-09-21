import { useQuery } from '@tanstack/react-query';
import { sharedStrings, type Locale } from '@clinic/localization';
import type { PlatformHealth } from '@clinic/desktop-bridge-contracts';

export function HealthPanel({ locale }: { locale: Locale }) {
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
      <p role="alert">
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
      <p data-testid="overall-status">{t.status[data.status]}</p>
      <p data-testid="health-message">{data.message}</p>
      <dl>
        {components.map(([label, status]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd data-testid={`component-${label}`}>{t.status[status]}</dd>
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
