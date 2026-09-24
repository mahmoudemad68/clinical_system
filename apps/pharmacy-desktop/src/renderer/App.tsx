import { useEffect, useMemo, useRef, useState } from 'react';
import { QueryClient, useQuery, useQueryClient } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import CssBaseline from '@mui/material/CssBaseline';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { ThemeProvider } from '@mui/material/styles';
import Typography from '@mui/material/Typography';
import {
  DEFAULT_LOCALE,
  direction,
  sharedStrings,
  type Locale,
} from '@clinic/localization';
import type {
  AuthMe,
  PharmacyOnboardRequest,
} from '@clinic/desktop-bridge-contracts';
import { pharmacyStrings } from './strings';
import { createPharmacyTheme } from './theme';
import { canManagePharmacyBranches } from './features/pharmacy-practice/eligibility';
import { PracticeWorkspace } from './features/pharmacy-practice/PracticeWorkspace';
import { navigatePractice, parsePracticeHash } from './features/pharmacy-practice/practiceRoute';
import { VerificationWorkspace } from './features/pharmacy-verification/VerificationWorkspace';

export const queryClient = new QueryClient({
  defaultOptions: { queries: { refetchOnWindowFocus: true, staleTime: 10_000 } },
});

const EMPTY_ONBOARDING: PharmacyOnboardRequest = {
  legalName: '',
  publicName: '',
  legalRegistrationIdentifier: '',
  branchPublicName: '',
  address: '',
  countryCode: 'EG',
  latitude: 30.0444,
  longitude: 31.2357,
  phone: '',
};

function isUncertainOutcome(code: string | undefined): boolean {
  return code === 'TIMEOUT' || code === 'UPSTREAM_FAILED';
}

function errorMessage(locale: Locale, code: string | undefined): string {
  const catalog = pharmacyStrings[locale].errors;
  if (code && code in catalog) {
    return catalog[code as keyof typeof catalog];
  }
  return catalog.generic;
}

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
    document.documentElement.setAttribute('dir', direction(locale));
    document.documentElement.setAttribute('lang', locale);
  }, [locale]);

  return [
    locale,
    (next: Locale) => {
      setLocale(next);
      void window.clinic.locale.set(next);
    },
  ];
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

function LoginPanel({
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
              data-testid="mfa-code"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(event) => setCode(event.target.value)}
            />
          </label>
        ) : null}
        {message ? (
          <p ref={alertRef} tabIndex={-1} role="alert" data-testid="login-error">
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

function SessionPanel({ locale, onSignedOut }: { locale: Locale; onSignedOut: () => void }) {
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

function OnboardingWizard({
  locale,
  onReady,
}: {
  locale: Locale;
  onReady: () => void;
}) {
  const t = pharmacyStrings[locale];
  const [step, setStep] = useState<'organization' | 'branch'>('organization');
  const [form, setForm] = useState<PharmacyOnboardRequest>(EMPTY_ONBOARDING);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const alertRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (message) {
      alertRef.current?.focus();
    }
  }, [message]);

  function update<K extends keyof PharmacyOnboardRequest>(key: K, value: PharmacyOnboardRequest[K]): void {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function submit(): Promise<void> {
    setBusy(true);
    setMessage(null);
    const result = await window.clinic.pharmacy.onboard(form);
    setBusy(false);
    if (!result.ok) {
      setMessage(errorMessage(locale, result.error.code));
      if (isUncertainOutcome(result.error.code)) {
        onReady();
      }
      return;
    }
    setForm(EMPTY_ONBOARDING);
    if (result.value.status === 'manual_review_required') {
      setMessage(t.manualReview);
      return;
    }
    onReady();
  }

  return (
    <Stack spacing={2} component="section" data-testid="onboarding-form">
      <Typography variant="h5" component="h2">
        {t.onboarding.title}
      </Typography>
      <Typography>{t.onboarding.intro}</Typography>
      <Typography component="p">{t.steps.organization} → {t.steps.branch}</Typography>
      {step === 'organization' ? (
        <Stack spacing={2}>
          <TextField
            label={t.onboarding.legalName}
            value={form.legalName}
            autoComplete="off"
            onChange={(event) => update('legalName', event.target.value)}
          />
          <TextField
            label={t.onboarding.publicName}
            value={form.publicName}
            autoComplete="off"
            onChange={(event) => update('publicName', event.target.value)}
          />
          <TextField
            label={t.onboarding.registration}
            value={form.legalRegistrationIdentifier}
            autoComplete="off"
            onChange={(event) => update('legalRegistrationIdentifier', event.target.value)}
          />
          <Button type="button" variant="contained" onClick={() => setStep('branch')}>
            {t.onboarding.continue}
          </Button>
        </Stack>
      ) : (
        <Stack spacing={2} component="form" onSubmit={(event) => { event.preventDefault(); void submit(); }}>
          <Typography variant="h6" component="h3">
            {t.onboarding.branchTitle}
          </Typography>
          <Typography>{t.onboarding.branchIntro}</Typography>
          <TextField
            label={t.onboarding.branchPublicName}
            value={form.branchPublicName}
            autoComplete="off"
            onChange={(event) => update('branchPublicName', event.target.value)}
          />
          <TextField
            label={t.onboarding.address}
            value={form.address}
            autoComplete="off"
            onChange={(event) => update('address', event.target.value)}
          />
          <TextField label={t.onboarding.country} value={t.onboarding.egypt} disabled helperText="EG" />
          <TextField
            label={t.onboarding.latitude}
            type="number"
            value={String(form.latitude)}
            onChange={(event) => update('latitude', Number(event.target.value))}
          />
          <TextField
            label={t.onboarding.longitude}
            type="number"
            value={String(form.longitude)}
            onChange={(event) => update('longitude', Number(event.target.value))}
          />
          <TextField
            label={t.onboarding.phone}
            value={form.phone}
            autoComplete="off"
            onChange={(event) => update('phone', event.target.value)}
          />
          <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
            <Button type="button" onClick={() => setStep('organization')}>
              {t.onboarding.back}
            </Button>
            <Button type="submit" variant="contained" disabled={busy}>
              {busy ? t.onboarding.submitting : t.onboarding.submit}
            </Button>
          </Stack>
        </Stack>
      )}
      {message ? (
        <Alert ref={alertRef} tabIndex={-1} severity="info" role="alert">
          {message}
        </Alert>
      ) : null}
    </Stack>
  );
}

function PharmacyWorkspace({ locale, onSignedOut }: { locale: Locale; onSignedOut: () => void }) {
  const t = pharmacyStrings[locale];
  const [route, setRoute] = useState(() => parsePracticeHash(window.location.hash));

  useEffect(() => {
    const onChange = () => setRoute(parsePracticeHash(window.location.hash));
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);

  const meQuery = useQuery({
    queryKey: ['auth', 'me', locale],
    queryFn: async () => {
      const result = await window.clinic.auth.me();
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value;
    },
  });
  const organizationQuery = useQuery({
    queryKey: ['pharmacy', 'organization', locale],
    enabled: meQuery.data?.accountType === 'pharmacy',
    queryFn: async () => {
      const result = await window.clinic.pharmacy.getOwnOrganization();
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value;
    },
  });

  const me: AuthMe | undefined = meQuery.data;
  const organization =
    organizationQuery.data?.present === true ? organizationQuery.data.organization : undefined;
  const canManage = canManagePharmacyBranches(me, organization);

  useEffect(() => {
    if (organizationQuery.isPending || meQuery.isPending) {
      return;
    }
    if (!canManage && route.name !== 'home') {
      window.location.hash = '';
    }
  }, [canManage, route.name, organizationQuery.isPending, meQuery.isPending]);

  if (meQuery.isPending) {
    return <Typography aria-busy="true">{t.workspace}</Typography>;
  }

  if (meQuery.error || !me) {
    return (
      <Alert severity="error" role="alert">
        {t.sessionExpired}
      </Alert>
    );
  }

  if (me.accountType !== 'pharmacy') {
    return (
      <Alert severity="error" role="alert" data-testid="account-denied">
        {t.accountDenied}
      </Alert>
    );
  }

  return (
    <Stack spacing={3} data-testid="pharmacy-workspace">
      <Typography variant="h4" component="h2">
        {t.workspace}
      </Typography>
      {organizationQuery.data?.present === true ? (
        <>
          {canManage ? (
            <nav data-testid="practice-branches-nav">
              <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
                <Button
                  type="button"
                  data-testid="open-verification"
                  onClick={() => navigatePractice({ name: 'home' })}
                >
                  {t.practice.verificationNav}
                </Button>
                <Button
                  type="button"
                  data-testid="open-practice-branches"
                  onClick={() => navigatePractice({ name: 'branches' })}
                >
                  {t.practice.nav}
                </Button>
              </Stack>
            </nav>
          ) : null}
          {canManage && route.name !== 'home' ? (
            <PracticeWorkspace locale={locale} route={route} />
          ) : (
            <VerificationWorkspace locale={locale} organization={organizationQuery.data.organization} />
          )}
          <Typography data-testid="no-phase10-nav">{t.practice.noPhase10}</Typography>
        </>
      ) : (
        <OnboardingWizard
          locale={locale}
          onReady={() => {
            void organizationQuery.refetch();
          }}
        />
      )}
      <SessionPanel locale={locale} onSignedOut={onSignedOut} />
    </Stack>
  );
}

export function App() {
  const [locale, setLocale] = useLocale();
  const [productName, setProductName] = useState('Clinic Pharmacy');
  const [signedIn, setSignedIn] = useState(false);
  const client = useQueryClient();
  const theme = useMemo(() => createPharmacyTheme(direction(locale)), [locale]);

  useEffect(() => {
    void window.clinic.app.metadata().then((result) => {
      if (result.ok) {
        setProductName(result.value.productName);
      }
    });
    void window.clinic.auth.me().then((result) => {
      setSignedIn(result.ok);
    });
  }, []);

  function signOut(): void {
    setSignedIn(false);
    client.clear();
    if (window.location.hash.startsWith('#/practice') || window.location.hash.startsWith('/practice')) {
      window.location.hash = '';
    }
  }

  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <Box component="main" sx={{ fontFamily: 'inherit', p: 3, maxWidth: 880, mx: 'auto' }}>
        <Stack
          direction={{ xs: 'column', sm: 'row' }}
          spacing={2}
          sx={{ justifyContent: 'space-between', alignItems: { sm: 'center' }, mb: 2 }}
        >
          <Typography data-testid="product-title" variant="h4" component="h1">
            {productName}
          </Typography>
          <label>
            {sharedStrings[locale].common.language}{' '}
            <select
              data-testid="language-select"
              aria-label={sharedStrings[locale].common.language}
              value={locale}
              onChange={(event) => setLocale(event.target.value as Locale)}
            >
              <option value="en">English</option>
              <option value="ar">العربية</option>
            </select>
          </label>
        </Stack>

        {signedIn ? (
          <PharmacyWorkspace locale={locale} onSignedOut={signOut} />
        ) : (
          <LoginPanel locale={locale} onAuthenticated={() => setSignedIn(true)} />
        )}

        <Typography data-testid="health-heading" variant="h5" component="h2" sx={{ mt: 4 }}>
          {sharedStrings[locale].health.title}
        </Typography>
        <HealthPanel locale={locale} />
      </Box>
    </ThemeProvider>
  );
}
