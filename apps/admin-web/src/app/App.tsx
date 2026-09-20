import Box from '@mui/material/Box';
import CircularProgress from '@mui/material/CircularProgress';
import Container from '@mui/material/Container';
import Typography from '@mui/material/Typography';
import { Navigate, Route, Routes } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { ReactNode } from 'react';
import { AdminShell } from '@/app/AdminShell';
import { AppTheme } from '@/app/AppTheme';
import { ADMIN_ROUTE_PATHS } from '@/app/routes';
import { LoginPanel } from '@/features/auth/LoginPanel';
import { UnauthorizedPanel } from '@/features/auth/UnauthorizedPanel';
import { VerificationCasePage } from '@/features/verification/VerificationCasePage';
import { VerificationQueuePage } from '@/features/verification/VerificationQueuePage';
import { SessionProvider } from '@/session/SessionProvider';
import { useSession } from '@/session/useSession';

function Bootstrapping() {
  const { t } = useTranslation();

  return (
    <Box
      sx={{
        display: 'flex',
        flexDirection: 'column',
        gap: 2,
        alignItems: 'center',
        justifyContent: 'center',
        minHeight: '40vh',
      }}
      aria-busy="true"
      aria-live="polite"
    >
      <CircularProgress aria-hidden />
      <Typography>{t('session.bootstrapping')}</Typography>
    </Box>
  );
}

function SignedOut() {
  const session = useSession();

  return (
    <Container maxWidth="sm" sx={{ py: 6 }}>
      <LoginPanel
        sessionExpired={session.status === 'session_expired'}
        onAuthenticated={() => {
          void session.refresh();
        }}
      />
    </Container>
  );
}

function HomeRedirect() {
  const session = useSession();
  if (session.status === 'authorized_reviewer') {
    return <Navigate to={ADMIN_ROUTE_PATHS.verificationQueue} replace />;
  }

  return <UnauthorizedPanel />;
}

function VerificationGate({ children }: { children: ReactNode }) {
  const session = useSession();
  if (session.status !== 'authorized_reviewer') {
    return <UnauthorizedPanel />;
  }

  return children;
}

function NotFoundPage() {
  const { t } = useTranslation();

  return (
    <Box>
      <Typography variant="h5" component="h1">
        {t('notFound.title')}
      </Typography>
      <Typography>{t('notFound.body')}</Typography>
    </Box>
  );
}

function AppRoutes() {
  const session = useSession();

  if (session.status === 'bootstrapping') {
    return <Bootstrapping />;
  }

  if (session.status === 'signed_out' || session.status === 'session_expired') {
    return <SignedOut />;
  }

  return (
    <Routes>
      <Route element={<AdminShell />}>
        <Route path={ADMIN_ROUTE_PATHS.home} element={<HomeRedirect />} />
        <Route
          path={ADMIN_ROUTE_PATHS.verificationQueue}
          element={
            <VerificationGate>
              <VerificationQueuePage />
            </VerificationGate>
          }
        />
        <Route
          path={ADMIN_ROUTE_PATHS.verificationCase}
          element={
            <VerificationGate>
              <VerificationCasePage />
            </VerificationGate>
          }
        />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}

export function App() {
  return (
    <AppTheme>
      <SessionProvider>
        <AppRoutes />
      </SessionProvider>
    </AppTheme>
  );
}
