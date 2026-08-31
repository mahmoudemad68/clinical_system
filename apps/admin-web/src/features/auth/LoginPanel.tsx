import { useState, type SubmitEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { apiClient, ApiError, toApiFailure } from '@/api/client';

interface LoginPanelProps {
  onAuthenticated: () => void;
}

/**
 * Cookie/CSRF admin login. Tokens never enter local or session storage.
 */
export function LoginPanel({ onAuthenticated }: LoginPanelProps) {
  const { t } = useTranslation();
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [challengeId, setChallengeId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: SubmitEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await apiClient.GET('/api/v1/auth/csrf');

      if (challengeId !== null) {
        const { error: verifyError, data } = await apiClient.POST(
          '/api/v1/auth/mfa/challenges/{id}/verify',
          {
            params: { path: { id: challengeId } },
            body: { code },
          },
        );
        if (verifyError || !data.data) {
          throw new ApiError(toApiFailure(verifyError));
        }
        onAuthenticated();
        return;
      }

      const { error: loginError, data } = await apiClient.POST('/api/v1/auth/login', {
        body: {
          phone,
          password,
          client_class: 'admin_web',
          platform: 'web',
          device_label: 'admin-browser',
        },
      });

      if (loginError || !data.data) {
        throw new ApiError(toApiFailure(loginError));
      }

      const payload = data.data;
      if (payload.mfa_required === true && typeof payload.challenge_id === 'string') {
        setChallengeId(payload.challenge_id);
        return;
      }

      onAuthenticated();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.failure.message : t('auth.failed'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form
      onSubmit={(event) => {
        void submit(event);
      }}
    >
      <h2>{t('auth.title')}</h2>
      <label>
        {t('auth.phone')}
        <input
          name="phone"
          type="tel"
          autoComplete="username"
          value={phone}
          onChange={(event) => {
            setPhone(event.target.value);
          }}
        />
      </label>
      <label>
        {t('auth.password')}
        <input
          name="password"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(event) => {
            setPassword(event.target.value);
          }}
        />
      </label>
      {challengeId !== null ? (
        <label>
          {t('auth.mfaCode')}
          <input
            name="code"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            value={code}
            onChange={(event) => {
              setCode(event.target.value);
            }}
          />
        </label>
      ) : null}
      {error ? <p role="alert">{error}</p> : null}
      <button type="submit" disabled={busy}>
        {t('auth.signIn')}
      </button>
    </form>
  );
}
