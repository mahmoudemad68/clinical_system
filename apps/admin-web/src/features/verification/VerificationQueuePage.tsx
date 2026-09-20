import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import FormControl from '@mui/material/FormControl';
import FormLabel from '@mui/material/FormLabel';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link as RouterLink } from 'react-router-dom';
import { ApiError } from '@/api/client';
import { SafeError } from '@/app/SafeError';
import { StatusChip } from '@/features/verification/StatusChip';
import { useVerificationQueue } from '@/features/verification/useVerificationQueue';
import { isRtl } from '@/i18n';
import type { QueueAssignmentFilter } from '@/session/keys';

function formatSubmitted(value: string | null, locale: string): string {
  if (value === null) {
    return '—';
  }

  return new Date(value).toLocaleString(locale.startsWith('ar') ? 'ar' : 'en');
}

export function VerificationQueuePage() {
  const { t, i18n } = useTranslation();
  const [assignment, setAssignment] = useState<QueueAssignmentFilter>('unassigned');
  const [cursor, setCursor] = useState<string | null>(null);
  const [cursorStack, setCursorStack] = useState<string[]>([]);
  const [cursorRecovered, setCursorRecovered] = useState(false);
  const query = useVerificationQueue(assignment, cursor);
  const cursorInvalid =
    query.error instanceof ApiError && query.error.failure.code === 'CURSOR_INVALID' && cursor !== null;

  useEffect(() => {
    if (!cursorInvalid) {
      return;
    }
    setCursor(null);
    setCursorStack([]);
    setCursorRecovered(true);
  }, [cursorInvalid]);

  function changeAssignment(next: QueueAssignmentFilter): void {
    setAssignment(next);
    setCursor(null);
    setCursorStack([]);
    setCursorRecovered(false);
  }

  function goNext(nextCursor: string): void {
    setCursorStack((stack) => [...stack, cursor ?? '']);
    setCursor(nextCursor);
    setCursorRecovered(false);
  }

  function goPrevious(): void {
    setCursorStack((stack) => {
      const copy = [...stack];
      const previous = copy.pop();
      setCursor(previous === undefined || previous === '' ? null : previous);
      return copy;
    });
    setCursorRecovered(false);
  }

  const language = i18n.resolvedLanguage ?? 'en';
  const items = query.data?.items ?? [];
  const pagination = query.data?.pagination;

  return (
    <Stack spacing={2}>
      <Typography variant="h4" component="h1">
        {t('queue.title')}
      </Typography>
      <Typography variant="body2" color="text.secondary">
        {t('queue.catalogueNote')}
      </Typography>

      <Stack
        spacing={2}
        sx={{ flexDirection: { xs: 'column', sm: 'row' }, alignItems: { sm: 'center' } }}
      >
        <FormControl>
          <FormLabel id="assignment-filter-label">{t('queue.assignment')}</FormLabel>
          <ToggleButtonGroup
            exclusive
            size="small"
            value={assignment}
            aria-labelledby="assignment-filter-label"
            onChange={(_event, next: QueueAssignmentFilter | null) => {
              if (next !== null) {
                changeAssignment(next);
              }
            }}
          >
            <ToggleButton value="unassigned">{t('queue.unassigned')}</ToggleButton>
            <ToggleButton value="mine">{t('queue.mine')}</ToggleButton>
            <ToggleButton value="all">{t('queue.all')}</ToggleButton>
          </ToggleButtonGroup>
        </FormControl>
        <Button
          type="button"
          variant="outlined"
          onClick={() => {
            void query.refetch();
          }}
        >
          {t('queue.refresh')}
        </Button>
      </Stack>

      {cursorRecovered ? (
        <Alert severity="info" role="status">
          {t('queue.cursorRecovered')}
        </Alert>
      ) : null}

      {query.isPending ? (
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }} aria-busy="true" aria-live="polite">
          <CircularProgress size={20} aria-hidden />
          <Typography>{t('queue.loading')}</Typography>
        </Stack>
      ) : null}

      {query.error &&
      !(query.error instanceof ApiError && query.error.failure.code === 'CURSOR_INVALID') ? (
        <SafeError failure={query.error instanceof ApiError ? query.error.failure : undefined} />
      ) : null}

      {query.isSuccess && items.length === 0 ? (
        <Alert severity="info" role="status">
          {t('queue.empty')}
        </Alert>
      ) : null}

      {items.length > 0 ? (
        <TableContainer component={Paper} sx={{ overflowX: 'auto' }}>
          <Table aria-label={t('queue.title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('queue.professionalName')}</TableCell>
                <TableCell>{t('queue.specialty')}</TableCell>
                <TableCell>{t('queue.caseStatus')}</TableCell>
                <TableCell>{t('queue.doctorStatus')}</TableCell>
                <TableCell>{t('queue.assignmentState')}</TableCell>
                <TableCell>{t('queue.submitted')}</TableCell>
                <TableCell>{t('queue.version')}</TableCell>
                <TableCell>{t('queue.openCase')}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {items.map((item) => (
                <TableRow key={item.case_id}>
                  <TableCell>{item.professional_display_name}</TableCell>
                  <TableCell>
                    {isRtl(language) ? item.specialty.label_ar : item.specialty.label_en}
                  </TableCell>
                  <TableCell>
                    <StatusChip value={item.case_status} />
                  </TableCell>
                  <TableCell>
                    <StatusChip value={item.doctor_verification_status} />
                  </TableCell>
                  <TableCell>
                    <StatusChip value={item.assignment} />
                  </TableCell>
                  <TableCell>
                    <time dateTime={item.submitted_at ?? undefined}>
                      {formatSubmitted(item.submitted_at, language)}
                    </time>
                  </TableCell>
                  <TableCell>{item.case_version}</TableCell>
                  <TableCell>
                    <Button
                      component={RouterLink}
                      to={`/verification/${item.case_id}`}
                      size="small"
                    >
                      {t('queue.openCase')}
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      ) : null}

      <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>
        <Button
          type="button"
          disabled={cursorStack.length === 0}
          onClick={goPrevious}
        >
          {t('queue.previous')}
        </Button>
        <Button
          type="button"
          disabled={pagination?.has_more !== true || typeof pagination.next !== 'string'}
          onClick={() => {
            if (typeof pagination?.next === 'string') {
              goNext(pagination.next);
            }
          }}
        >
          {t('queue.next')}
        </Button>
      </Box>
    </Stack>
  );
}
