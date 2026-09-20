import Alert from '@mui/material/Alert';
import { useTranslation } from 'react-i18next';
import type { ApiFailure } from '@/api/client';
import { API_ERROR_CODES } from '@clinic/error-handling';

const ERROR_MESSAGE_CODES = new Set<string>([...API_ERROR_CODES, 'NETWORK_UNAVAILABLE', 'NETWORK_ERROR']);

interface SafeErrorProps {
  failure?: ApiFailure | undefined;
  fallbackKey?: string;
}

export function SafeError({ failure, fallbackKey = 'errors.generic' }: SafeErrorProps) {
  const { t } = useTranslation();
  const code = failure?.code;
  const messageKey = typeof code === 'string' && ERROR_MESSAGE_CODES.has(code) ? `errors.${code}` : fallbackKey;

  return (
    <Alert severity="error" role="alert">
      {t(messageKey, { defaultValue: t(fallbackKey) })}
      {failure?.requestId ? (
        <span>
          {' '}
          {t('errors.requestId')}: <code>{failure.requestId}</code>
        </span>
      ) : null}
    </Alert>
  );
}
