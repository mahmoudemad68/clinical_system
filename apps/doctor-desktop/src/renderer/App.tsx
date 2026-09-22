import { useEffect, useMemo, useState } from 'react';
import { QueryClient, useQueryClient } from '@tanstack/react-query';
import Box from '@mui/material/Box';
import CssBaseline from '@mui/material/CssBaseline';
import Stack from '@mui/material/Stack';
import { ThemeProvider } from '@mui/material/styles';
import Typography from '@mui/material/Typography';
import {
  DEFAULT_LOCALE,
  direction,
  sharedStrings,
  type Locale,
} from '@clinic/localization';
import { HealthPanel } from './components/HealthPanel';
import { LoginPanel } from './components/LoginPanel';
import { DoctorWorkspace } from './features/doctor-shell/DoctorWorkspace';
import { createDoctorTheme } from './theme';

export const queryClient = new QueryClient({
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

export function App() {
  const [locale, setLocale] = useLocale();
  const [productName, setProductName] = useState('Clinic Doctor');
  const [signedIn, setSignedIn] = useState(false);
  const client = useQueryClient();
  const theme = useMemo(() => createDoctorTheme(direction(locale)), [locale]);

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
    if (window.location.hash.startsWith('#/practice')) {
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
          <DoctorWorkspace locale={locale} onSignedOut={signOut} />
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
