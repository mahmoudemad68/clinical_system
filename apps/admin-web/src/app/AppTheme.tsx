import CssBaseline from '@mui/material/CssBaseline';
import { ThemeProvider } from '@mui/material/styles';
import { useMemo, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { isRtl } from '@/i18n';
import { createAdminTheme } from '@/app/theme';
import { setClientLocale } from '@/api/locale';

export function AppTheme({ children }: { children: ReactNode }) {
  const { i18n } = useTranslation();
  const language = i18n.resolvedLanguage ?? 'en';
  const direction: 'ltr' | 'rtl' = isRtl(language) ? 'rtl' : 'ltr';
  const theme = useMemo(() => createAdminTheme(direction), [direction]);

  setClientLocale(language);

  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      {children}
    </ThemeProvider>
  );
}
