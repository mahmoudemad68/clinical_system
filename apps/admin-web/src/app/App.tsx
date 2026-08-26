import { useTranslation } from 'react-i18next';
import { HealthPanel } from '@/features/health/HealthPanel';
import { LoginPanel } from '@/features/auth/LoginPanel';
import { SUPPORTED_LOCALES } from '@/i18n';
import { useState } from 'react';
import { apiClient } from '@/api/client';

/**
 * Phase 00 admin shell.
 *
 * Deliberately minimal: this phase delivers no clinical, verification, catalog,
 * or analytics capability. Authorization in the UI affects discoverability
 * only; Laravel remains authoritative (phase file, "Client architecture").
 */
export function App() {
  const { t, i18n } = useTranslation();
  const [signedIn, setSignedIn] = useState(false);

  return (
    <main>
      <header>
        <h1>{t('app.title')}</h1>

        <label>
          {t('app.language')}
          <select
            value={i18n.resolvedLanguage}
            onChange={(event) => void i18n.changeLanguage(event.target.value)}
          >
            {SUPPORTED_LOCALES.map((locale) => (
              <option key={locale} value={locale}>
                {locale === 'ar' ? 'العربية' : 'English'}
              </option>
            ))}
          </select>
        </label>
        {signedIn ? (
          <button
            type="button"
            onClick={() => {
              void apiClient.POST('/api/v1/auth/logout', {}).then(() => setSignedIn(false));
            }}
          >
            {t('auth.signOut')}
          </button>
        ) : null}
      </header>

      {signedIn ? null : <LoginPanel onAuthenticated={() => setSignedIn(true)} />}
      <HealthPanel />
    </main>
  );
}
