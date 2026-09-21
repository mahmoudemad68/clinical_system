import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import FormControl from '@mui/material/FormControl';
import FormHelperText from '@mui/material/FormHelperText';
import InputLabel from '@mui/material/InputLabel';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import Select from '@mui/material/Select';
import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect, useRef, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { ApiError } from '@/api/client';
import { SafeError } from '@/app/SafeError';
import {
  createDecisionIdempotency,
  type DecisionPayload,
} from '@/features/verification/idempotency';
import {
  ENGINEERING_DEFAULT_DECISIONS,
  reasonsForDecision,
  type EngineeringDefaultDecision,
} from '@/features/verification/reasons';
import { useDecideVerificationCase } from '@/features/verification/useVerificationCase';

const NOTES_MAX = 2000;

const decisionSchema = z
  .object({
    decision: z.enum(ENGINEERING_DEFAULT_DECISIONS),
    reason_code: z.string().min(1).max(64),
    notes: z.string().max(NOTES_MAX),
  })
  .superRefine((value, ctx) => {
    const allowed = reasonsForDecision(value.decision);
    if (!allowed.includes(value.reason_code)) {
      ctx.addIssue({
        code: 'custom',
        path: ['reason_code'],
        message: 'invalid_pair',
      });
    }
  });

type DecisionFormValues = z.infer<typeof decisionSchema>;

interface DecisionFormProps {
  caseId: string;
  professionalDisplayName: string;
  expectedCaseVersion: number;
  applicantType?: 'doctor' | 'pharmacy';
  onConflict: () => void;
}

export function DecisionForm({
  caseId,
  professionalDisplayName,
  expectedCaseVersion,
  applicantType = 'doctor',
  onConflict,
}: DecisionFormProps) {
  const { t } = useTranslation();
  const decide = useDecideVerificationCase(caseId);
  const idempotency = useRef(createDecisionIdempotency());
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [pendingPayload, setPendingPayload] = useState<DecisionPayload | null>(null);
  const confirmTitleId = 'decision-confirm-title';
  const confirmDescId = 'decision-confirm-description';

  useEffect(() => {
    idempotency.current.reset();
  }, [caseId]);

  const form = useForm<DecisionFormValues>({
    resolver: zodResolver(decisionSchema),
    defaultValues: {
      decision: 'approved',
      reason_code: 'approved',
      notes: '',
    },
  });

  const [decision, setDecision] = useState<EngineeringDefaultDecision>('approved');
  const reasons = reasonsForDecision(decision);

  function syncReason(next: EngineeringDefaultDecision): void {
    const allowed = reasonsForDecision(next);
    if (!allowed.includes(form.getValues('reason_code'))) {
      const first = allowed[0];
      if (first !== undefined) {
        form.setValue('reason_code', first);
      }
    }
  }

  function openConfirm(values: DecisionFormValues): void {
    const payload: DecisionPayload = {
      decision: values.decision,
      reason_code: values.reason_code,
      expected_case_version: expectedCaseVersion,
      ...(values.notes.trim() === '' ? {} : { notes: values.notes.trim() }),
    };
    setPendingPayload(payload);
    setConfirmOpen(true);
  }

  async function submitConfirmed(): Promise<void> {
    if (pendingPayload === null) {
      return;
    }

    const key = idempotency.current.keyFor(pendingPayload);

    try {
      await decide.mutateAsync({ payload: pendingPayload, idempotencyKey: key });
      setConfirmOpen(false);
      setPendingPayload(null);
      form.reset({ decision: 'approved', reason_code: 'approved', notes: '' });
    } catch (error) {
      setConfirmOpen(false);
      if (
        error instanceof ApiError &&
        (error.failure.code === 'VERSION_CONFLICT' ||
          error.failure.code === 'STATE_CONFLICT' ||
          error.failure.code === 'IDEMPOTENCY_KEY_REUSED')
      ) {
        idempotency.current.reset();
        onConflict();
      }
    }
  }

  const selectedDecision = pendingPayload?.decision as EngineeringDefaultDecision | undefined;

  return (
    <Stack
      component="form"
      spacing={2}
      onSubmit={(event) => {
        void form.handleSubmit(openConfirm)(event);
      }}
      noValidate
    >
      <Typography variant="h6" component="h2">
        {t('case.decision')}
      </Typography>
      <Typography variant="body2" color="text.secondary">
        {t('case.catalogueNote')}
      </Typography>
      <Typography variant="body2">
        {t('case.expectedVersion')}: {String(expectedCaseVersion)}
      </Typography>

      <Controller
        name="decision"
        control={form.control}
        render={({ field, fieldState }) => (
          <FormControl fullWidth error={fieldState.invalid}>
            <InputLabel id="decision-label">{t('case.decision')}</InputLabel>
            <Select
              {...field}
              labelId="decision-label"
              label={t('case.decision')}
              onChange={(event) => {
                const next = event.target.value;
                field.onChange(next);
                setDecision(next);
                syncReason(next);
              }}
            >
              {ENGINEERING_DEFAULT_DECISIONS.map((value) => (
                <MenuItem key={value} value={value}>
                  {t(`decision.${value}`)}
                </MenuItem>
              ))}
            </Select>
            {fieldState.error ? <FormHelperText>{t('errors.VALIDATION_FAILED')}</FormHelperText> : null}
          </FormControl>
        )}
      />

      <Controller
        name="reason_code"
        control={form.control}
        render={({ field, fieldState }) => (
          <FormControl fullWidth error={fieldState.invalid}>
            <InputLabel id="reason-label">{t('case.reason')}</InputLabel>
            <Select {...field} labelId="reason-label" label={t('case.reason')}>
              {reasons.map((value) => (
                <MenuItem key={value} value={value}>
                  {t(`decision.reasons.${value}`)}
                </MenuItem>
              ))}
            </Select>
            {fieldState.error ? <FormHelperText>{t('errors.VALIDATION_FAILED')}</FormHelperText> : null}
          </FormControl>
        )}
      />

      <Controller
        name="notes"
        control={form.control}
        render={({ field, fieldState }) => (
          <TextField
            {...field}
            label={t('case.notes')}
            helperText={fieldState.error?.message ?? t('case.notesHelp')}
            error={fieldState.invalid}
            multiline
            minRows={3}
            fullWidth
            slotProps={{ htmlInput: { maxLength: NOTES_MAX } }}
          />
        )}
      />

      {decide.error instanceof ApiError ? <SafeError failure={decide.error.failure} /> : null}

      <Button type="submit" variant="contained" disabled={decide.isPending}>
        {t('case.submitDecision')}
      </Button>

      <Dialog
        open={confirmOpen}
        onClose={() => {
          setConfirmOpen(false);
        }}
        aria-labelledby={confirmTitleId}
        aria-describedby={confirmDescId}
      >
        <DialogTitle id={confirmTitleId}>{t('case.confirmTitle')}</DialogTitle>
        <DialogContent>
          <DialogContentText id={confirmDescId}>
            {t('case.confirmBody', { name: professionalDisplayName })}
          </DialogContentText>
          <DialogContentText>
            {t('case.confirmDecision', {
              decision: selectedDecision ? t(`decision.${selectedDecision}`) : '',
            })}
          </DialogContentText>
          <DialogContentText>
            {t('case.confirmReason', {
              reason: pendingPayload ? t(`decision.reasons.${pendingPayload.reason_code}`) : '',
            })}
          </DialogContentText>
          {pendingPayload?.decision === 'approved' ? (
            <Alert severity="warning" sx={{ mt: 2 }}>
              {t(applicantType === 'pharmacy' ? 'case.confirmApprovalCaveatPharmacy' : 'case.confirmApprovalCaveat')}
            </Alert>
          ) : null}
        </DialogContent>
        <DialogActions>
          <Button
            type="button"
            onClick={() => {
              setConfirmOpen(false);
            }}
          >
            {t('case.confirmCancel')}
          </Button>
          <Button
            type="button"
            variant="contained"
            onClick={() => {
              void submitConfirmed();
            }}
            disabled={decide.isPending}
          >
            {t('case.confirmSubmit')}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
