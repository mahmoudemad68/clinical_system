import Alert from '@mui/material/Alert';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useTranslation } from 'react-i18next';

export function UnauthorizedPanel() {
  const { t } = useTranslation();

  return (
    <Stack spacing={2} sx={{ maxWidth: 640 }}>
      <Typography variant="h4" component="h1">
        {t('session.unauthorizedTitle')}
      </Typography>
      <Alert severity="info" role="status">
        {t('session.unauthorizedBody')}
      </Alert>
      <Typography>{t('session.higherAssurance')}</Typography>
    </Stack>
  );
}
