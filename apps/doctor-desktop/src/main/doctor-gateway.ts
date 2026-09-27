import { app } from 'electron';
import type {
  DoctorClinicInviteStaffRequest,
  DoctorClinicInviteStaffResponse,
  DoctorClinicLocationCreateRequest,
  DoctorClinicLocationCreateResponse,
  DoctorClinicLocationUpdateRequest,
  DoctorClinicLocationView,
  DoctorClinicLocationsListRequest,
  DoctorClinicLocationsListResponse,
  DoctorClinicMembershipView,
  DoctorClinicMembershipsResponse,
  DoctorClinicRevokeMembershipRequest,
  DoctorOnboardRequest,
  DoctorOnboardResponse,
  DoctorOwnProfileResponse,
  DoctorProfileView,
  DoctorSpecialtiesResponse,
  DoctorUploadStatus,
  DoctorVerificationOpenResponse,
  DoctorVerificationStatus,
  DoctorVerificationSubmitResponse,
} from '@clinic/desktop-bridge-contracts';
import { type EvidenceMediaType } from './evidence-handles';
import { IntentKeyStore } from './intent-keys';
import { parseIssuedUploadTarget } from './upload-target';
import {
  GatewayError,
  coreJsonRequest,
  coreJsonRequestEnvelope,
  currentAccountType,
  platformGateway,
  putIssuedUploadBytes,
} from './platform-gateway';

export const ONBOARD_INTENT = 'doctor.onboard';
export const OPEN_CASE_INTENT = 'doctor.verification.open';
export const SUBMIT_INTENT = 'doctor.verification.submit';
export const UPLOAD_CREATE_INTENT = 'doctor.verification.upload.create';
export const UPLOAD_COMPLETE_INTENT = 'doctor.verification.upload.complete';
export const LOCATION_CREATE_INTENT = 'doctor.clinic.location.create';
export const STAFF_INVITE_INTENT = 'doctor.clinic.staff.invite';

export const doctorIntentKeys = new IntentKeyStore();

type ApiProfile = {
  doctor_id: string;
  professional_display_name: string;
  specialty_id: string;
  specialty_code: string;
  specialty_label_ar: string;
  specialty_label_en: string;
  verification_status: DoctorProfileView['verificationStatus'];
  public_status: DoctorProfileView['publicStatus'];
  version: number;
  approved_at: string | null;
  suspended_at: string | null;
  created_at: string;
  updated_at: string;
};

function requireDoctorAccount(locale: string): Promise<void> {
  const cached = currentAccountType();
  if (cached === 'doctor') {
    return Promise.resolve();
  }
  return platformGateway.me(locale).then((me) => {
    if (me.accountType !== 'doctor') {
      throw new GatewayError('PERMISSION_DENIED');
    }
  });
}

function mapProfile(raw: ApiProfile): DoctorProfileView {
  return {
    doctorId: raw.doctor_id,
    professionalDisplayName: raw.professional_display_name,
    specialtyId: raw.specialty_id,
    specialtyCode: raw.specialty_code,
    specialtyLabelAr: raw.specialty_label_ar,
    specialtyLabelEn: raw.specialty_label_en,
    verificationStatus: raw.verification_status,
    publicStatus: raw.public_status,
    version: raw.version,
    approvedAt: raw.approved_at,
    suspendedAt: raw.suspended_at,
    createdAt: raw.created_at,
    updatedAt: raw.updated_at,
  };
}

function mapUploadStatus(raw: {
  upload_id: string;
  requirement_code: string;
  state: DoctorUploadStatus['state'];
  rejection_reason: DoctorUploadStatus['rejectionReason'];
  expires_at: string;
  completed_at: string | null;
}): DoctorUploadStatus {
  return {
    uploadId: raw.upload_id,
    requirementCode: raw.requirement_code,
    state: raw.state,
    rejectionReason: raw.rejection_reason,
    expiresAt: raw.expires_at,
    completedAt: raw.completed_at,
  };
}

type ApiLocation = {
  location_id: string;
  public_name: string;
  country_code: DoctorClinicLocationView['countryCode'];
  status: DoctorClinicLocationView['status'];
  version: number;
  created_at: string;
  updated_at: string;
  address?: string;
  latitude?: number;
  longitude?: number;
};

function mapLocation(raw: ApiLocation): DoctorClinicLocationView {
  return {
    locationId: raw.location_id,
    publicName: raw.public_name,
    countryCode: raw.country_code,
    status: raw.status,
    version: raw.version,
    createdAt: raw.created_at,
    updatedAt: raw.updated_at,
    ...(typeof raw.address === 'string' ? { address: raw.address } : {}),
    ...(typeof raw.latitude === 'number' ? { latitude: raw.latitude } : {}),
    ...(typeof raw.longitude === 'number' ? { longitude: raw.longitude } : {}),
  };
}

function paginationFromMeta(meta: Record<string, unknown>): {
  hasMore: boolean;
  nextCursor: string | null;
} {
  const pagination = meta['pagination'];
  if (!pagination || typeof pagination !== 'object') {
    return { hasMore: false, nextCursor: null };
  }
  const record = pagination as Record<string, unknown>;
  const next = record['next'];
  return {
    hasMore: record['has_more'] === true,
    nextCursor: typeof next === 'string' && next.length > 0 ? next : null,
  };
}

function locationsListPath(input: DoctorClinicLocationsListRequest): string {
  const params = new URLSearchParams();
  if (typeof input.cursor === 'string') {
    params.set('cursor', input.cursor);
  }
  if (typeof input.limit === 'number') {
    params.set('limit', String(input.limit));
  }
  const query = params.toString();
  return query === '' ? '/api/v1/clinic-locations' : `/api/v1/clinic-locations?${query}`;
}

type ApiMembership = {
  membership_id: string;
  role: DoctorClinicMembershipView['role'];
  status: DoctorClinicMembershipView['status'];
  version: number;
  invited_at: string | null;
  accepted_at: string | null;
  revoked_at: string | null;
};

function mapMembership(raw: ApiMembership): DoctorClinicMembershipView {
  return {
    membershipId: raw.membership_id,
    role: raw.role,
    status: raw.status,
    version: raw.version,
    invitedAt: raw.invited_at,
    acceptedAt: raw.accepted_at,
    revokedAt: raw.revoked_at,
  };
}

export const doctorGateway = {
  async getOwnProfile(locale: string): Promise<DoctorOwnProfileResponse> {
    await requireDoctorAccount(locale);
    try {
      const data = await coreJsonRequest<ApiProfile>('GET', '/api/v1/doctors/me/profile', locale);
      return { present: true, profile: mapProfile(data) };
    } catch (error) {
      if (error instanceof GatewayError && error.failureCode === 'NOT_FOUND') {
        return { present: false };
      }
      throw error;
    }
  },

  async listSpecialties(locale: string): Promise<DoctorSpecialtiesResponse> {
    await requireDoctorAccount(locale);
    const data = await coreJsonRequest<{
      specialties: Array<{
        specialty_id: string;
        code: string;
        label_ar: string;
        label_en: string;
        sort_order: number;
      }>;
    }>('GET', '/api/v1/doctors/specialties', locale);
    return {
      specialties: data.specialties.map((row) => ({
        specialtyId: row.specialty_id,
        code: row.code,
        labelAr: row.label_ar,
        labelEn: row.label_en,
        sortOrder: row.sort_order,
      })),
    };
  },

  async onboard(locale: string, input: DoctorOnboardRequest): Promise<DoctorOnboardResponse> {
    await requireDoctorAccount(locale);
    const fingerprint = doctorIntentKeys.fingerprint({
      nationalId: input.nationalId,
      professionalDisplayName: input.professionalDisplayName,
      specialtyId: input.specialtyId,
      syndicateNumber: input.syndicateNumber,
    });
    const key = doctorIntentKeys.keyFor(ONBOARD_INTENT, fingerprint);
    try {
      const data = await coreJsonRequest<{
        status: 'profile_ready' | 'manual_review_required';
        doctor_id?: string;
        version?: number;
      }>(
        'POST',
        '/api/v1/doctors/onboarding',
        locale,
        {
          national_id: input.nationalId,
          professional_display_name: input.professionalDisplayName,
          specialty_id: input.specialtyId,
          ...(input.syndicateNumber !== null ? { syndicate_number: input.syndicateNumber } : {}),
        },
        { 'Idempotency-Key': key },
      );
      if (data.status === 'manual_review_required') {
        const mapped = { status: 'manual_review_required' as const };
        doctorIntentKeys.retireWhenDelivered(ONBOARD_INTENT, fingerprint, key);
        return mapped;
      }
      if (typeof data.doctor_id !== 'string' || typeof data.version !== 'number') {
        throw new GatewayError('UPSTREAM_FAILED');
      }
      const mapped = {
        status: 'profile_ready' as const,
        doctorId: data.doctor_id,
        version: data.version,
      };
      doctorIntentKeys.retireWhenDelivered(ONBOARD_INTENT, fingerprint, key);
      return mapped;
    } catch (error) {
      if (
        error instanceof GatewayError &&
        (error.failureCode === 'VALIDATION_FAILED' || error.failureCode === 'PERMISSION_DENIED')
      ) {
        doctorIntentKeys.retireWhenDelivered(ONBOARD_INTENT, fingerprint, key);
      }
      throw error;
    }
  },

  async openVerificationCase(locale: string): Promise<DoctorVerificationOpenResponse> {
    await requireDoctorAccount(locale);
    const fingerprint = doctorIntentKeys.fingerprint({ intent: OPEN_CASE_INTENT });
    const key = doctorIntentKeys.keyFor(OPEN_CASE_INTENT, fingerprint);
    try {
      const data = await coreJsonRequest<{
        status: 'ready';
        doctor_id: string;
        case_id: string;
        case_status: 'draft' | 'pending_review';
        case_version: number;
        profile_version: number;
      }>('POST', '/api/v1/doctors/me/verification-cases', locale, {}, { 'Idempotency-Key': key });
      const mapped = {
        status: 'ready' as const,
        doctorId: data.doctor_id,
        caseId: data.case_id,
        caseStatus: data.case_status,
        caseVersion: data.case_version,
        profileVersion: data.profile_version,
      };
      doctorIntentKeys.retireWhenDelivered(OPEN_CASE_INTENT, fingerprint, key);
      return mapped;
    } catch (error) {
      throw error;
    }
  },

  async verificationStatus(locale: string): Promise<DoctorVerificationStatus> {
    await requireDoctorAccount(locale);
    const data = await coreJsonRequest<{
      doctor_id: string;
      profile_verification_status: DoctorVerificationStatus['profileVerificationStatus'];
      profile_public_status: DoctorVerificationStatus['profilePublicStatus'];
      profile_version: number;
      case_id: string | null;
      case_status: DoctorVerificationStatus['caseStatus'];
      case_version: number | null;
      case_type: 'doctor_verification' | null;
      submitted_at: string | null;
      decided_at: string | null;
      decision: DoctorVerificationStatus['decision'];
      reason_code: string | null;
      applicant_safe_explanation?: string | null;
      documents: Array<{
        document_id: string;
        requirement_code: string;
        scan_status: 'pending' | 'clean' | 'failed';
        status: 'quarantined' | 'available' | 'rejected' | 'retired';
        uploaded_at: string;
      }>;
    }>('GET', '/api/v1/doctors/me/verification-status', locale);

    return {
      applicantType: 'doctor',
      doctorId: data.doctor_id,
      profileVerificationStatus: data.profile_verification_status,
      profilePublicStatus: data.profile_public_status,
      profileVersion: data.profile_version,
      caseId: data.case_id,
      caseStatus: data.case_status,
      caseVersion: data.case_version,
      caseType: data.case_type,
      submittedAt: data.submitted_at,
      decidedAt: data.decided_at,
      decision: data.decision,
      reasonCode: data.reason_code,
      applicantSafeExplanation: data.applicant_safe_explanation ?? null,
      documents: data.documents.map((document) => ({
        documentId: document.document_id,
        requirementCode: document.requirement_code,
        scanStatus: document.scan_status,
        status: document.status,
        uploadedAt: document.uploaded_at,
      })),
    };
  },

  async submitVerification(
    locale: string,
    input: { caseVersion: number; profileVersion: number },
  ): Promise<DoctorVerificationSubmitResponse> {
    await requireDoctorAccount(locale);
    const fingerprint = doctorIntentKeys.fingerprint(input);
    const key = doctorIntentKeys.keyFor(SUBMIT_INTENT, fingerprint);
    try {
      const data = await coreJsonRequest<{
        status: 'submitted';
        doctor_id: string;
        case_id: string;
        case_status: 'pending_review';
        case_version: number;
        profile_version: number;
        profile_verification_status: 'pending_review';
      }>(
        'POST',
        '/api/v1/doctors/me/verification-submissions',
        locale,
        {
          case_version: input.caseVersion,
          profile_version: input.profileVersion,
        },
        { 'Idempotency-Key': key },
      );
      const mapped = {
        status: 'submitted' as const,
        doctorId: data.doctor_id,
        caseId: data.case_id,
        caseStatus: data.case_status,
        caseVersion: data.case_version,
        profileVersion: data.profile_version,
        profileVerificationStatus: data.profile_verification_status,
      };
      doctorIntentKeys.retireWhenDelivered(SUBMIT_INTENT, fingerprint, key);
      return mapped;
    } catch (error) {
      if (
        error instanceof GatewayError &&
        (error.failureCode === 'VERSION_CONFLICT' || error.failureCode === 'STATE_CONFLICT')
      ) {
        doctorIntentKeys.retireWhenDelivered(SUBMIT_INTENT, fingerprint, key);
      }
      throw error;
    }
  },

  async uploadEvidence(
    locale: string,
    input: {
      caseId: string;
      bytes: Buffer;
      sizeBytes: number;
      candidateMediaType: EvidenceMediaType;
      requirementCode: string;
    },
  ): Promise<DoctorUploadStatus> {
    await requireDoctorAccount(locale);
    const createFingerprint = doctorIntentKeys.fingerprint({
      caseId: input.caseId,
      sizeBytes: input.sizeBytes,
      mediaType: input.candidateMediaType,
      requirement: input.requirementCode,
    });
    const createKey = doctorIntentKeys.keyFor(UPLOAD_CREATE_INTENT, createFingerprint);

    type CreateRaw = {
      upload_id: string;
      requirement_code: string;
      state: DoctorUploadStatus['state'];
      rejection_reason: DoctorUploadStatus['rejectionReason'];
      expires_at: string;
      completed_at: string | null;
      upload_target?: unknown;
    };

    const created = await coreJsonRequest<CreateRaw>(
      'POST',
      '/api/v1/verification-uploads',
      locale,
      {
        case_id: input.caseId,
        requirement_code: input.requirementCode,
        expected_size_bytes: input.sizeBytes,
        declared_media_type: input.candidateMediaType,
      },
      { 'Idempotency-Key': createKey },
    );

    const target = parseIssuedUploadTarget(created.upload_target, app.isPackaged);
    await putIssuedUploadBytes(target.url, target.headers, input.bytes);

    const completeFingerprint = doctorIntentKeys.fingerprint({ uploadId: created.upload_id });
    const completeKey = doctorIntentKeys.keyFor(UPLOAD_COMPLETE_INTENT, completeFingerprint);
    try {
      const completed = await coreJsonRequest<{
        upload_id: string;
        requirement_code: string;
        state: DoctorUploadStatus['state'];
        rejection_reason: DoctorUploadStatus['rejectionReason'];
        expires_at: string;
        completed_at: string | null;
      }>(
        'POST',
        `/api/v1/verification-uploads/${created.upload_id}/complete`,
        locale,
        {},
        { 'Idempotency-Key': completeKey },
      );
      const mapped = mapUploadStatus(completed);
      doctorIntentKeys.retireWhenDelivered(UPLOAD_CREATE_INTENT, createFingerprint, createKey);
      doctorIntentKeys.retireWhenDelivered(UPLOAD_COMPLETE_INTENT, completeFingerprint, completeKey);
      return mapped;
    } catch (error) {
      throw error;
    }
  },

  async uploadStatus(locale: string, uploadId: string): Promise<DoctorUploadStatus> {
    await requireDoctorAccount(locale);
    const data = await coreJsonRequest<{
      upload_id: string;
      requirement_code: string;
      state: DoctorUploadStatus['state'];
      rejection_reason: DoctorUploadStatus['rejectionReason'];
      expires_at: string;
      completed_at: string | null;
    }>('GET', `/api/v1/verification-uploads/${uploadId}`, locale);
    return mapUploadStatus(data);
  },

  async listLocations(
    locale: string,
    input: DoctorClinicLocationsListRequest,
  ): Promise<DoctorClinicLocationsListResponse> {
    await requireDoctorAccount(locale);
    const envelope = await coreJsonRequestEnvelope<ApiLocation[]>(
      'GET',
      locationsListPath(input),
      locale,
    );
    const pagination = paginationFromMeta(envelope.meta);
    return {
      locations: envelope.data.map(mapLocation),
      hasMore: pagination.hasMore,
      nextCursor: pagination.nextCursor,
    };
  },

  async createLocation(
    locale: string,
    input: DoctorClinicLocationCreateRequest,
  ): Promise<DoctorClinicLocationCreateResponse> {
    await requireDoctorAccount(locale);
    const fingerprint = doctorIntentKeys.fingerprint({
      publicName: input.publicName,
      address: input.address,
      countryCode: input.countryCode,
      latitude: input.latitude,
      longitude: input.longitude,
    });
    const key = doctorIntentKeys.keyFor(LOCATION_CREATE_INTENT, fingerprint);
    try {
      const data = await coreJsonRequest<{
        location_id: string;
        status: 'active';
        version: number;
      }>(
        'POST',
        '/api/v1/clinic-locations',
        locale,
        {
          public_name: input.publicName,
          address: input.address,
          country_code: input.countryCode,
          latitude: input.latitude,
          longitude: input.longitude,
        },
        { 'Idempotency-Key': key },
      );
      const mapped = {
        locationId: data.location_id,
        status: data.status,
        version: data.version,
      };
      doctorIntentKeys.retireWhenDelivered(LOCATION_CREATE_INTENT, fingerprint, key);
      return mapped;
    } catch (error) {
      if (
        error instanceof GatewayError &&
        (error.failureCode === 'VALIDATION_FAILED' || error.failureCode === 'PERMISSION_DENIED')
      ) {
        doctorIntentKeys.retireWhenDelivered(LOCATION_CREATE_INTENT, fingerprint, key);
      }
      throw error;
    }
  },

  async getLocation(locale: string, locationId: string): Promise<DoctorClinicLocationView> {
    await requireDoctorAccount(locale);
    const data = await coreJsonRequest<ApiLocation>(
      'GET',
      `/api/v1/clinic-locations/${locationId}`,
      locale,
    );
    return mapLocation(data);
  },

  async updateLocation(
    locale: string,
    input: DoctorClinicLocationUpdateRequest,
  ): Promise<DoctorClinicLocationView> {
    await requireDoctorAccount(locale);
    const body: Record<string, unknown> = {
      expected_version: input.expectedVersion,
    };
    if (input.publicName !== undefined) {
      body['public_name'] = input.publicName;
    }
    if (input.address !== undefined) {
      body['address'] = input.address;
    }
    if (input.countryCode !== undefined) {
      body['country_code'] = input.countryCode;
    }
    if (input.latitude !== undefined) {
      body['latitude'] = input.latitude;
    }
    if (input.longitude !== undefined) {
      body['longitude'] = input.longitude;
    }
    const data = await coreJsonRequest<ApiLocation>(
      'PATCH',
      `/api/v1/clinic-locations/${input.locationId}`,
      locale,
      body,
    );
    return mapLocation(data);
  },

  async inviteStaff(
    locale: string,
    input: DoctorClinicInviteStaffRequest,
  ): Promise<DoctorClinicInviteStaffResponse> {
    await requireDoctorAccount(locale);
    const fingerprint = doctorIntentKeys.fingerprint({
      locationId: input.locationId,
      phone: input.phone,
    });
    const key = doctorIntentKeys.keyFor(STAFF_INVITE_INTENT, fingerprint);
    try {
      const envelope = await coreJsonRequestEnvelope<{
        invitation_id: string;
        location_id: string;
        status: 'pending';
        expires_at: string;
      }>(
        'POST',
        `/api/v1/clinic-locations/${input.locationId}/staff-invitations`,
        locale,
        { phone: input.phone },
        { 'Idempotency-Key': key },
      );
      const mapped = {
        invitationId: envelope.data.invitation_id,
        locationId: envelope.data.location_id,
        status: envelope.data.status,
        expiresAt: envelope.data.expires_at,
        existingPending: envelope.status === 200,
      };
      doctorIntentKeys.retireWhenDelivered(STAFF_INVITE_INTENT, fingerprint, key);
      return mapped;
    } catch (error) {
      if (
        error instanceof GatewayError &&
        (error.failureCode === 'VALIDATION_FAILED' || error.failureCode === 'PERMISSION_DENIED')
      ) {
        doctorIntentKeys.retireWhenDelivered(STAFF_INVITE_INTENT, fingerprint, key);
      }
      throw error;
    }
  },

  async listMemberships(locale: string, locationId: string): Promise<DoctorClinicMembershipsResponse> {
    await requireDoctorAccount(locale);
    const data = await coreJsonRequest<ApiMembership[]>(
      'GET',
      `/api/v1/clinic-locations/${locationId}/memberships`,
      locale,
    );
    return { memberships: data.map(mapMembership) };
  },

  async revokeMembership(
    locale: string,
    input: DoctorClinicRevokeMembershipRequest,
  ): Promise<DoctorClinicMembershipView> {
    await requireDoctorAccount(locale);
    const data = await coreJsonRequest<ApiMembership>(
      'DELETE',
      `/api/v1/clinic-locations/${input.locationId}/memberships/${input.membershipId}`,
      locale,
    );
    return mapMembership(data);
  },

  clearIntents(): void {
    doctorIntentKeys.clearAll();
  },
};

export function resetDoctorGatewayState(): void {
  doctorIntentKeys.clearAll();
}
