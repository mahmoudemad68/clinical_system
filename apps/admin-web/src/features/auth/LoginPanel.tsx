import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useState, type SubmitEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError, apiClient, toApiFailure } from '@/api/client';
import { SafeError } from '@/app/SafeError';
import type { ApiFailure } from '@/api/client';

interface LoginPanelProps {
  onAuthenticated: () => void;
  sessionExpired?: boolean;
}

/**
 * Cookie/CSRF admin login. Tokens, passwords, and MFA codes never enter
 * local or session storage.
 */
export function LoginPanel({ onAuthenticated, sessionExpired = false }: LoginPanelProps) {
  const { t } = useTranslation();
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [challengeId, setChallengeId] = useState<string | null>(null);
  const [failure, setFailure] = useState<ApiFailure | undefined>(undefined);
  const [busy, setBusy] = useState(false);

  async function submit(event: SubmitEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setFailure(undefined);

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
        setPassword('');
        setCode('');
        setChallengeId(null);
        await apiClient.GET('/api/v1/auth/csrf');
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
        setPassword('');
        return;
      }

      setPassword('');
      await apiClient.GET('/api/v1/auth/csrf');
      onAuthenticated();
    } catch (caught) {
      setFailure(caught instanceof ApiError ? caught.failure : undefined);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Box component="form" onSubmit={(event) => void submit(event)} sx={{ maxWidth: 420 }}>
      <Stack spacing={2}>
        <Typography variant="h5" component="h1">
          {t('auth.title')}
        </Typography>
        {sessionExpired ? (
          <Alert severity="warning" role="status">
            {t('auth.sessionExpired')}
          </Alert>
        ) : null}
        <TextField
          name="phone"
          type="tel"
          label={t('auth.phone')}
          autoComplete="username"
          value={phone}
          onChange={(event) => {
            setPhone(event.target.value);
          }}
          required
          fullWidth
        />
        <TextField
          name="password"
          type="password"
          label={t('auth.password')}
          autoComplete="current-password"
          value={password}
          onChange={(event) => {
            setPassword(event.target.value);
          }}
          required={challengeId === null}
          fullWidth
        />
        {challengeId !== null ? (
          <TextField
            name="code"
            label={t('auth.mfaCode')}
            inputMode="numeric"
            autoComplete="one-time-code"
            slotProps={{ htmlInput: { maxLength: 6 } }}
            value={code}
            onChange={(event) => {
              setCode(event.target.value);
            }}
            required
            fullWidth
          />
        ) : null}
        {failure ? <SafeError failure={failure} fallbackKey="auth.failed" /> : null}
        <Button type="submit" variant="contained" disabled={busy}>
          {t('auth.signIn')}
        </Button>
      </Stack>
    </Box>
  );
}
