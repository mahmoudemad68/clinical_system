import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import FormControl from '@mui/material/FormControl';
import InputLabel from '@mui/material/InputLabel';
import MenuItem from '@mui/material/MenuItem';
import Select from '@mui/material/Select';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useEffect, useMemo, useRef, useState, type ChangeEvent, type SubmitEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link as RouterLink } from 'react-router-dom';
import { uuidV7 } from '@clinic/api-client';
import {
  DOCTOR_REQUIREMENTS,
  requirementLabel,
  type DoctorRequirementCode,
} from '@clinic/verification-policy';
import { ApiError, apiClient, putBoundedUploadGrant, toApiFailure, type ApiFailure } from '@/api/client';
import { SafeError } from '@/app/SafeError';
import { ADMIN_ROUTE_PATHS } from '@/app/routes';
import { isRtl } from '@/i18n';
import { useSession } from '@/session/useSession';
import { UnauthorizedPanel } from '@/features/auth/UnauthorizedPanel';

interface Specialty {
  specialty_id: string;
  code: string;
  label_ar: string;
  label_en: string;
  sort_order: number;
}

interface CreatedApplicant {
  doctor_id: string;
  profile_version: number;
  case_id: string;
  case_version: number;
}

function evidenceType(file: File): 'application/pdf' | 'image/jpeg' | 'image/png' | null {
  if (file.type === 'application/pdf' || file.type === 'image/jpeg' || file.type === 'image/png') {
    return file.type;
  }

  return null;
}

export function CreateDoctorPage() {
  const { t, i18n } = useTranslation();
  const session = useSession();
  const language = i18n.resolvedLanguage ?? 'en';
  const [phone, setPhone] = useState('');
  const [nationalId, setNationalId] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [specialtyId, setSpecialtyId] = useState('');
  const [syndicate, setSyndicate] = useState('');
  const [password, setPassword] = useState('');
  const [evidenceSource, setEvidenceSource] = useState<'in_person_originals' | 'certified_copy'>(
    'in_person_originals',
  );
  const [specialties, setSpecialties] = useState<Specialty[] | null>(null);
  const [created, setCreated] = useState<CreatedApplicant | null>(null);
  const [files, setFiles] = useState<Partial<Record<DoctorRequirementCode, File>>>({});
  const [ready, setReady] = useState<Partial<Record<DoctorRequirementCode, boolean>>>({});
  const [submitted, setSubmitted] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | undefined>(undefined);
  const [busy, setBusy] = useState(false);
  const createKey = useMemo(() => uuidV7(), []);
  const uploadKeys = useRef<Partial<Record<DoctorRequirementCode, { upload: string; complete: string }>>>({});
  const submitKey = useMemo(() => uuidV7(), []);

  useEffect(() => {
    if (!session.canCreateDoctor || specialties !== null || created !== null) {
      return;
    }
    void (async () => {
      const { data, error, response } = await apiClient.GET('/api/v1/admin/doctor-applicants/specialties');
      if (error || !data.data) {
        setFailure(toApiFailure(error, response.status));
        return;
      }
      setSpecialties(data.data.specialties);
    })().catch(() => {
      setFailure({ code: 'NETWORK_UNAVAILABLE', message: 'The service could not be reached.', status: 0 });
    });
  }, [created, session.canCreateDoctor, specialties]);

  if (!session.canCreateDoctor) {
    return <UnauthorizedPanel />;
  }

  if (specialties !== null && specialties.length === 0 && created === null) {
    return (
      <Stack spacing={2} sx={{ maxWidth: 560 }}>
        <Typography variant="h4" component="h1">
          {t('createDoctor.title')}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          {t('createDoctor.intro')}
        </Typography>
        {failure ? <SafeError failure={failure} /> : null}
        <Alert severity="warning" role="status">
          {t('createDoctor.catalogueUnavailable')}
        </Alert>
      </Stack>
    );
  }

  async function createApplicant(event: SubmitEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setFailure(undefined);
    try {
      const { data, error, response } = await apiClient.POST('/api/v1/admin/doctor-applicants', {
        body: {
          phone,
          national_id: nationalId,
          professional_display_name: displayName,
          specialty_id: specialtyId,
          password,
          evidence_source: evidenceSource,
          ...(syndicate.trim() === '' ? {} : { syndicate_number: syndicate.trim() }),
        },
        params: { header: { 'Idempotency-Key': createKey } },
      });
      if (error || !data.data) {
        throw new ApiError(toApiFailure(error, response.status));
      }
      const payload = data.data;
      if (
        payload.status !== 'created' ||
        typeof payload.doctor_id !== 'string' ||
        typeof payload.case_id !== 'string' ||
        typeof payload.case_version !== 'number' ||
        typeof payload.profile_version !== 'number'
      ) {
        throw new ApiError({
          code: 'STATE_CONFLICT',
          message: 'The applicant could not be created.',
          status: response.status,
        });
      }
      setCreated({
        doctor_id: payload.doctor_id,
        profile_version: payload.profile_version,
        case_id: payload.case_id,
        case_version: payload.case_version,
      });
      setNationalId('');
      setPassword('');
      setPhone('');
      setSyndicate('');
    } catch (caught) {
      setFailure(caught instanceof ApiError ? caught.failure : undefined);
    } finally {
      setBusy(false);
    }
  }

  async function uploadEvidence(code: DoctorRequirementCode): Promise<void> {
    const file = files[code];
    if (created === null || file === undefined) {
      return;
    }
    const declared = evidenceType(file);
    if (declared === null) {
      setFailure({ code: 'VALIDATION_FAILED', message: 'The form could not be submitted.', status: 422 });
      return;
    }
    setBusy(true);
    setFailure(undefined);
    try {
      const keys = uploadKeys.current[code] ?? { upload: uuidV7(), complete: uuidV7() };
      uploadKeys.current[code] = keys;
      const { data, error, response } = await apiClient.POST(
        '/api/v1/admin/doctor-applicants/{doctor_id}/verification-uploads',
        {
          body: {
            case_id: created.case_id,
            requirement_code: code,
            expected_size_bytes: file.size,
            declared_media_type: declared,
          },
          params: {
            path: { doctor_id: created.doctor_id },
            header: { 'Idempotency-Key': keys.upload },
          },
        },
      );
      if (error || !data.data) {
        throw new ApiError(toApiFailure(error, response.status));
      }
      const target = data.data.upload_target;
      const put = await putBoundedUploadGrant(target.url, target.method, target.headers, file);
      if (!put.ok) {
        throw new ApiError({
          code: 'DEPENDENCY_UNAVAILABLE',
          message: 'The request could not be completed.',
          status: put.status,
        });
      }
      const completed = await apiClient.POST('/api/v1/verification-uploads/{upload_id}/complete', {
        params: {
          path: { upload_id: data.data.upload_id },
          header: { 'Idempotency-Key': keys.complete },
        },
        body: {},
      });
      if (completed.error || !completed.data.data) {
        throw new ApiError(toApiFailure(completed.error, completed.response.status));
      }
      const uploadId = data.data.upload_id;
      const deadline = Date.now() + 45_000;
      while (Date.now() < deadline) {
        const status = await apiClient.GET('/api/v1/verification-uploads/{upload_id}', {
          params: { path: { upload_id: uploadId } },
        });
        if (status.error || !status.data.data) {
          throw new ApiError(toApiFailure(status.error, status.response.status));
        }
        if (status.data.data.state === 'available') {
          setReady((current) => ({ ...current, [code]: true }));
          return;
        }
        if (status.data.data.state === 'rejected') {
          uploadKeys.current[code] = { upload: uuidV7(), complete: uuidV7() };
          throw new ApiError({
            code: 'STATE_CONFLICT',
            message: 'The request could not be completed.',
            status: 409,
          });
        }
        await new Promise((resolve) => {
          window.setTimeout(resolve, 1000);
        });
      }
      throw new ApiError({
        code: 'DEPENDENCY_UNAVAILABLE',
        message: 'The request could not be completed.',
        status: 503,
      });
    } catch (caught) {
      setFailure(caught instanceof ApiError ? caught.failure : undefined);
    } finally {
      setBusy(false);
    }
  }

  async function submitCase(): Promise<void> {
    if (created === null) {
      return;
    }
    setBusy(true);
    setFailure(undefined);
    try {
      const { error, response } = await apiClient.POST(
        '/api/v1/admin/doctor-applicants/{doctor_id}/verification-submissions',
        {
          params: {
            path: { doctor_id: created.doctor_id },
            header: { 'Idempotency-Key': submitKey },
          },
          body: {
            case_version: created.case_version,
            profile_version: created.profile_version,
          },
        },
      );
      if (error) {
        throw new ApiError(toApiFailure(error, response.status));
      }
      setSubmitted(true);
    } catch (caught) {
      setFailure(caught instanceof ApiError ? caught.failure : undefined);
    } finally {
      setBusy(false);
    }
  }

  if (submitted) {
    return (
      <Stack spacing={2} sx={{ maxWidth: 560 }}>
        <Typography variant="h4" component="h1">
          {t('createDoctor.title')}
        </Typography>
        {failure ? <SafeError failure={failure} /> : null}
        <Alert severity="success" role="status">
          {t('createDoctor.submitted')}
        </Alert>
        <Button component={RouterLink} to={ADMIN_ROUTE_PATHS.verificationQueue} variant="contained">
          {t('createDoctor.backToQueue')}
        </Button>
      </Stack>
    );
  }

  return (
    <Stack spacing={3} sx={{ maxWidth: 560 }}>
      <Typography variant="h4" component="h1">
        {t('createDoctor.title')}
      </Typography>
      <Typography variant="body2" color="text.secondary">
        {t('createDoctor.intro')}
      </Typography>
      {failure ? <SafeError failure={failure} /> : null}

      {created === null ? (
        <Box component="form" onSubmit={(event) => void createApplicant(event)}>
          <Stack spacing={2}>
            <TextField
              name="professional_display_name"
              label={t('createDoctor.displayName')}
              value={displayName}
              onChange={(event) => {
                setDisplayName(event.target.value);
              }}
              required
              fullWidth
            />
            <TextField
              name="phone"
              type="tel"
              label={t('createDoctor.phone')}
              autoComplete="off"
              value={phone}
              onChange={(event) => {
                setPhone(event.target.value);
              }}
              required
              fullWidth
            />
            <TextField
              name="national_id"
              label={t('createDoctor.nationalId')}
              autoComplete="off"
              value={nationalId}
              onChange={(event) => {
                setNationalId(event.target.value);
              }}
              required
              fullWidth
            />
            <TextField
              name="syndicate_number"
              label={t('createDoctor.syndicate')}
              autoComplete="off"
              value={syndicate}
              onChange={(event) => {
                setSyndicate(event.target.value);
              }}
              fullWidth
            />
            <FormControl fullWidth required>
              <InputLabel id="specialty-label">{t('createDoctor.specialty')}</InputLabel>
              <Select
                labelId="specialty-label"
                label={t('createDoctor.specialty')}
                value={specialtyId}
                onChange={(event) => {
                  if (typeof event.target.value === 'string') {
                    setSpecialtyId(event.target.value);
                  }
                }}
              >
                {(specialties ?? []).map((row) => (
                  <MenuItem key={row.specialty_id} value={row.specialty_id}>
                    {isRtl(language) ? row.label_ar : row.label_en}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
            <FormControl fullWidth required>
              <InputLabel id="evidence-source-label">{t('createDoctor.evidenceSource')}</InputLabel>
              <Select
                labelId="evidence-source-label"
                label={t('createDoctor.evidenceSource')}
                value={evidenceSource}
                onChange={(event) => {
                  setEvidenceSource(event.target.value === 'certified_copy' ? 'certified_copy' : 'in_person_originals');
                }}
              >
                <MenuItem value="in_person_originals">{t('createDoctor.inPersonOriginals')}</MenuItem>
                <MenuItem value="certified_copy">{t('createDoctor.certifiedCopy')}</MenuItem>
              </Select>
            </FormControl>
            <TextField
              name="password"
              type="password"
              label={t('createDoctor.password')}
              autoComplete="new-password"
              value={password}
              onChange={(event) => {
                setPassword(event.target.value);
              }}
              required
              fullWidth
            />
            <Button type="submit" variant="contained" disabled={busy || specialtyId === ''}>
              {t('createDoctor.submitCreate')}
            </Button>
          </Stack>
        </Box>
      ) : null}

      {created !== null ? (
        <Stack spacing={2}>
          <Alert severity="info" role="status">
            {t('createDoctor.draftReady')}
          </Alert>
          {DOCTOR_REQUIREMENTS.map((requirement) => {
            const label = requirementLabel(requirement, isRtl(language) ? 'ar' : 'en');
            const requiredLabel = requirement.required
              ? t('createDoctor.required')
              : t('createDoctor.optional');
            const file = files[requirement.code];
            const slotReady = ready[requirement.code] === true;
            return (
              <Stack key={requirement.code} spacing={1} data-testid={`requirement-slot-${requirement.code}`}>
                <Typography>
                  {label} ({requiredLabel})
                </Typography>
                <Button component="label" variant="outlined" data-testid={`choose-file-${requirement.code}`}>
                  {t('createDoctor.chooseFile')}: {label}
                  <input
                    type="file"
                    accept="application/pdf,image/jpeg,image/png"
                    hidden
                    data-testid={`file-input-${requirement.code}`}
                    onChange={(event: ChangeEvent<HTMLInputElement>) => {
                      const next = event.target.files?.[0] ?? null;
                      setFiles((current) => {
                        const copy = { ...current };
                        if (next === null) {
                          delete copy[requirement.code];
                        } else {
                          copy[requirement.code] = next;
                        }
                        return copy;
                      });
                      setReady((current) => ({ ...current, [requirement.code]: false }));
                      uploadKeys.current[requirement.code] = { upload: uuidV7(), complete: uuidV7() };
                    }}
                  />
                </Button>
                {file !== undefined ? <Typography>{file.name}</Typography> : null}
                {slotReady ? (
                  <Alert severity="success" role="status" data-testid={`evidence-ready-${requirement.code}`}>
                    {t('createDoctor.evidenceReady')}
                  </Alert>
                ) : null}
                <Button
                  type="button"
                  variant="contained"
                  disabled={busy || file === undefined || slotReady}
                  data-testid={`upload-evidence-${requirement.code}`}
                  onClick={() => void uploadEvidence(requirement.code)}
                >
                  {t('createDoctor.uploadEvidence')}
                </Button>
              </Stack>
            );
          })}
          <Button
            type="button"
            variant="contained"
            disabled={
              busy ||
              !DOCTOR_REQUIREMENTS.filter((requirement) => requirement.required).every(
                (requirement) => ready[requirement.code] === true,
              )
            }
            onClick={() => void submitCase()}
          >
            {t('createDoctor.submitReview')}
          </Button>
        </Stack>
      ) : null}
    </Stack>
  );
}
