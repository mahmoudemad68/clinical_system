import { useEffect, useRef, useState } from 'react';
import Stack from '@mui/material/Stack';
import { sharedStrings, type Locale } from '@clinic/localization';

export function LoginPanel({
  locale,
  onAuthenticated,
}: {
  locale: Locale;
  onAuthenticated: () => void;
}) {
  const t = sharedStrings[locale].common;
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [challengeId, setChallengeId] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [secure, setSecure] = useState<boolean | null>(null);
  const alertRef = useRef<HTMLParagraphElement>(null);

  useEffect(() => {
    void window.clinic.auth.secureStatus().then((result) => {
      setSecure(result.ok ? result.value.available : false);
    });
  }, []);

  useEffect(() => {
    if (message) {
      alertRef.current?.focus();
    }
  }, [message]);

  if (secure === false) {
    return (
      <p role="alert" data-testid="keystore-unavailable">
        {t.offline}
      </p>
    );
  }

  return (
    <form
      data-testid="login-form"
      onSubmit={(event) => {
        event.preventDefault();
        void (async () => {
          setMessage(null);
          if (challengeId !== null) {
            const verified = await window.clinic.auth.verifyMfa({ challengeId, code });
            if (!verified.ok) {
              setMessage(verified.error.message);
              return;
            }
            setPassword('');
            setCode('');
            onAuthenticated();
            return;
          }

          const result = await window.clinic.auth.login({ phone, password, deviceLabel: 'desktop' });
          if (!result.ok) {
            setMessage(result.error.message);
            return;
          }
          if (result.value.mfaRequired && result.value.challengeId) {
            setChallengeId(result.value.challengeId);
            return;
          }
          setPassword('');
          onAuthenticated();
        })();
      }}
    >
      <Stack spacing={2}>
        <label>
          {t.phone}
          <input
            name="phone"
            type="tel"
            autoComplete="username"
            value={phone}
            onChange={(event) => setPhone(event.target.value)}
          />
        </label>
        <label>
          {t.password}
          <input
            name="password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
        </label>
        {challengeId !== null ? (
          <label>
            {t.mfaCode}
            <input
              name="code"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              data-testid="mfa-code"
              value={code}
              onChange={(event) => setCode(event.target.value)}
            />
          </label>
        ) : null}
        {message ? (
          <p ref={alertRef} tabIndex={-1} role="alert">
            {message}
          </p>
        ) : null}
        <button data-testid="sign-in" type="submit">
          {t.signIn}
        </button>
      </Stack>
    </form>
  );
}
