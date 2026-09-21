import { useQuery } from '@tanstack/react-query';
import { sharedStrings, type Locale } from '@clinic/localization';

export function SessionPanel({ locale, onSignedOut }: { locale: Locale; onSignedOut: () => void }) {
  const t = sharedStrings[locale].common;
  const { data } = useQuery({
    queryKey: ['auth', 'sessions', locale],
    queryFn: async () => {
      const result = await window.clinic.auth.sessions();
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value.sessions;
    },
  });

  return (
    <section data-testid="session-panel">
      <h2>{t.sessions}</h2>
      <ul>
        {(data ?? []).map((session) => (
          <li key={session.sessionId}>
            {session.sessionKind}
            <button
              type="button"
              onClick={() => {
                void window.clinic.auth.revokeSession(session.sessionId).then((result) => {
                  if (result.ok) {
                    onSignedOut();
                  }
                });
              }}
            >
              {t.revoke}
            </button>
          </li>
        ))}
      </ul>
      <button
        type="button"
        data-testid="sign-out"
        onClick={() => {
          void window.clinic.auth.logout().then(() => onSignedOut());
        }}
      >
        {t.signOut}
      </button>
    </section>
  );
}
