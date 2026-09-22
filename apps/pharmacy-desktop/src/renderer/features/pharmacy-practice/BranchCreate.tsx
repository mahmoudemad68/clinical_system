import { useState } from 'react';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useQueryClient } from '@tanstack/react-query';
import type { Locale } from '@clinic/localization';
import { pharmacyStrings } from '../../strings';
import { BranchForm, branchFormError } from './BranchForm';
import { navigatePractice } from './practiceRoute';

export function BranchCreate({ locale }: { locale: Locale }) {
  const t = pharmacyStrings[locale];
  const client = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  return (
    <Stack spacing={2} data-testid="practice-branch-create">
      <Typography variant="h5" component="h2">
        {t.practice.createTitle}
      </Typography>
      <Typography>{t.practice.createIntro}</Typography>
      <BranchForm
        locale={locale}
        mode="create"
        busy={busy}
        message={message}
        onCancel={() => navigatePractice({ name: 'branches' })}
        onSubmit={async (input) => {
          setBusy(true);
          setMessage(null);
          const created = await window.clinic.pharmacy.createBranch(input);
          if (!created.ok) {
            setBusy(false);
            setMessage(branchFormError(locale, created.error.code));
            return;
          }
          await client.invalidateQueries({ queryKey: ['pharmacy', 'branches'] });
          setBusy(false);
          navigatePractice({
            name: 'detail',
            branchId: created.value.branchId,
            tab: 'details',
          });
        }}
      />
    </Stack>
  );
}
