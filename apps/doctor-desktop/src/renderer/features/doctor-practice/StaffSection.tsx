import { useRef, useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useQuery } from '@tanstack/react-query';
import type { Locale } from '@clinic/localization';
import type { DoctorClinicInviteStaffResponse, DoctorClinicMembershipView } from '@clinic/desktop-bridge-contracts';
import { doctorErrorMessage, doctorStrings } from '../../strings/doctor';

export function StaffSection({ locale, locationId }: { locale: Locale; locationId: string }) {
  const t = doctorStrings[locale];
  const [phone, setPhone] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [invitation, setInvitation] = useState<DoctorClinicInviteStaffResponse | null>(null);
  const [pendingRevoke, setPendingRevoke] = useState<DoctorClinicMembershipView | null>(null);
  const alertRef = useRef<HTMLDivElement>(null);

  const membershipsQuery = useQuery({
    queryKey: ['doctor', 'memberships', locationId, locale],
    queryFn: async () => {
      const result = await window.clinic.doctor.listMemberships({ locationId });
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value.memberships;
    },
  });

  async function invite(): Promise<void> {
    setBusy(true);
    setMessage(null);
    const submittedPhone = phone;
    const result = await window.clinic.doctor.inviteStaff({ locationId, phone: submittedPhone });
    setPhone('');
    setBusy(false);
    if (!result.ok) {
      setMessage(doctorErrorMessage(locale, result.error.code));
      alertRef.current?.focus();
      return;
    }
    setInvitation(result.value);
  }

  async function revoke(membership: DoctorClinicMembershipView): Promise<void> {
    setBusy(true);
    setMessage(null);
    const result = await window.clinic.doctor.revokeMembership({
      locationId,
      membershipId: membership.membershipId,
    });
    setBusy(false);
    setPendingRevoke(null);
    if (!result.ok) {
      setMessage(doctorErrorMessage(locale, result.error.code));
      return;
    }
    await membershipsQuery.refetch();
  }

  return (
    <Stack spacing={3} data-testid="location-staff">
      <Stack
        spacing={2}
        component="form"
        data-testid="staff-invite-form"
        onSubmit={(event) => {
          event.preventDefault();
          void invite();
        }}
      >
        <Typography variant="h6" component="h3">
          {t.practice.inviteTitle}
        </Typography>
        <Typography>{t.practice.inviteIntro}</Typography>
        {message ? (
          <Alert ref={alertRef} tabIndex={-1} severity="error" role="alert">
            {message}
          </Alert>
        ) : null}
        <TextField
          id="staff-invite-phone"
          label={t.practice.phone}
          value={phone}
          type="tel"
          required
          autoComplete="off"
          data-testid="staff-invite-phone"
          onChange={(event) => setPhone(event.target.value)}
        />
        <Button type="submit" variant="contained" disabled={busy || phone.trim() === ''} data-testid="send-invite">
          {busy ? t.practice.sendingInvite : t.practice.sendInvite}
        </Button>
      </Stack>

      {invitation ? (
        <Alert
          severity="success"
          role="status"
          data-testid="invitation-result"
          data-existing-pending={invitation.existingPending ? 'true' : 'false'}
        >
          {invitation.existingPending ? t.practice.inviteAlreadyPending : t.practice.inviteSent}{' '}
          {t.practice.invitationId}: {invitation.invitationId}. {t.practice.inviteExpires}: {invitation.expiresAt}.
        </Alert>
      ) : null}

      <Stack spacing={1} data-testid="memberships-list">
        <Stack direction="row" spacing={2} sx={{ alignItems: 'center' }}>
          <Typography variant="h6" component="h3">
            {t.practice.membershipsTitle}
          </Typography>
          <Button type="button" data-testid="refresh-memberships" onClick={() => void membershipsQuery.refetch()}>
            {t.practice.refresh}
          </Button>
        </Stack>
        {membershipsQuery.isPending ? (
          <Typography aria-busy="true">{t.practice.loading}</Typography>
        ) : null}
        {membershipsQuery.isError ? (
          <Alert severity="error" role="alert">
            {doctorErrorMessage(
              locale,
              membershipsQuery.error instanceof Error ? membershipsQuery.error.message : undefined,
            )}
          </Alert>
        ) : null}
        {membershipsQuery.isSuccess && membershipsQuery.data.length === 0 ? (
          <Alert severity="info" role="status" data-testid="memberships-empty">
            {t.practice.membershipsEmpty}
          </Alert>
        ) : null}
        <Stack component="ul" spacing={1} sx={{ listStyle: 'none', p: 0, m: 0 }}>
          {(membershipsQuery.data ?? []).map((membership) => (
            <Stack
              component="li"
              key={membership.membershipId}
              spacing={0.5}
              data-testid={`membership-${membership.membershipId}`}
              data-membership-status={membership.status}
            >
              <Typography>
                {t.practice.membershipRole}: {t.practice.roles[membership.role]}
              </Typography>
              <Typography>
                {t.practice.membershipStatus}: {t.practice.membershipStatuses[membership.status]}
              </Typography>
              <Typography>
                {t.practice.membershipVersion}: {String(membership.version)}
              </Typography>
              {membership.invitedAt ? (
                <Typography>
                  {t.practice.invitedAt}: {membership.invitedAt}
                </Typography>
              ) : null}
              {membership.acceptedAt ? (
                <Typography>
                  {t.practice.acceptedAt}: {membership.acceptedAt}
                </Typography>
              ) : null}
              {membership.revokedAt ? (
                <Typography>
                  {t.practice.revokedAt}: {membership.revokedAt}
                </Typography>
              ) : null}
              {membership.status !== 'revoked' ? (
                <Button
                  type="button"
                  data-testid={`revoke-${membership.membershipId}`}
                  onClick={() => setPendingRevoke(membership)}
                >
                  {t.practice.revoke}
                </Button>
              ) : null}
            </Stack>
          ))}
        </Stack>
      </Stack>

      <Dialog
        open={pendingRevoke !== null}
        onClose={() => setPendingRevoke(null)}
        aria-labelledby="revoke-membership-title"
      >
        <DialogTitle id="revoke-membership-title">{t.practice.revokeConfirmTitle}</DialogTitle>
        <DialogContent>
          <Typography>{t.practice.revokeConfirmBody}</Typography>
        </DialogContent>
        <DialogActions>
          <Button type="button" onClick={() => setPendingRevoke(null)}>
            {t.practice.revokeCancel}
          </Button>
          <Button
            type="button"
            color="error"
            data-testid="confirm-revoke"
            disabled={busy}
            onClick={() => {
              if (pendingRevoke) {
                void revoke(pendingRevoke);
              }
            }}
          >
            {busy ? t.practice.revoking : t.practice.revokeConfirm}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
