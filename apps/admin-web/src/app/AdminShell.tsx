import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import AppBar from '@mui/material/AppBar';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Container from '@mui/material/Container';
import FormControl from '@mui/material/FormControl';
import InputLabel from '@mui/material/InputLabel';
import MenuItem from '@mui/material/MenuItem';
import Select from '@mui/material/Select';
import Toolbar from '@mui/material/Toolbar';
import Typography from '@mui/material/Typography';
import { Link as RouterLink, Outlet } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { SUPPORTED_LOCALES } from '@/i18n';
import { useSession } from '@/session/useSession';
import { HealthPanel } from '@/features/health/HealthPanel';
import { ADMIN_ROUTE_PATHS } from '@/app/routes';

export function AdminShell() {
  const { t, i18n } = useTranslation();
  const session = useSession();

  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
      <AppBar position="static" color="primary" enableColorOnDark>
        <Toolbar sx={{ gap: 2, flexWrap: 'wrap' }}>
          <Typography variant="h6" component="h1" sx={{ flexGrow: 1 }}>
            {t('app.title')}
          </Typography>
          {session.canReviewVerification ? (
            <Button
              color="inherit"
              component={RouterLink}
              to={ADMIN_ROUTE_PATHS.verificationQueue}
            >
              {t('shell.verification')}
            </Button>
          ) : null}
          {session.canCreateDoctor ? (
            <Button
              color="inherit"
              component={RouterLink}
              to={ADMIN_ROUTE_PATHS.createDoctor}
            >
              {t('shell.createDoctor')}
            </Button>
          ) : null}
          <FormControl size="small" sx={{ minWidth: 140 }}>
            <InputLabel id="language-label">{t('app.language')}</InputLabel>
            <Select
              labelId="language-label"
              label={t('app.language')}
              value={i18n.resolvedLanguage ?? 'en'}
              onChange={(event) => {
                void i18n.changeLanguage(event.target.value);
              }}
            >
              {SUPPORTED_LOCALES.map((locale) => (
                <MenuItem key={locale} value={locale}>
                  {locale === 'ar' ? 'العربية' : 'English'}
                </MenuItem>
              ))}
            </Select>
          </FormControl>
          <Button
            color="inherit"
            type="button"
            onClick={() => {
              void session.logout();
            }}
          >
            {t('auth.signOut')}
          </Button>
        </Toolbar>
      </AppBar>

      <Container component="main" maxWidth="lg" sx={{ py: 3, flexGrow: 1, width: '100%' }}>
        <Outlet />
      </Container>

      <Container maxWidth="lg" sx={{ pb: 3 }}>
        <Accordion>
          <AccordionSummary>
            <Typography>{t('health.diagnostics')}</Typography>
          </AccordionSummary>
          <AccordionDetails>
            <HealthPanel />
          </AccordionDetails>
        </Accordion>
      </Container>
    </Box>
  );
}
