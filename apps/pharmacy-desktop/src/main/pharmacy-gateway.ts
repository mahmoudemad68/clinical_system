import { app } from 'electron';
import type {
  PharmacyBranchCreateRequest,
  PharmacyBranchCreateResponse,
  PharmacyBranchInviteOperatorRequest,
  PharmacyBranchInviteOperatorResponse,
  PharmacyBranchMembershipView,
  PharmacyBranchMembershipsResponse,
  PharmacyBranchPrivateView,
  PharmacyBranchRevokeMembershipRequest,
  PharmacyBranchUpdateRequest,
  PharmacyBranchesListRequest,
  PharmacyBranchesListResponse,
  PharmacyOnboardRequest,
  PharmacyOnboardResponse,
  PharmacyOrganizationView,
  PharmacyOwnOrganizationResponse,
  PharmacyUploadStatus,
  PharmacyVerificationOpenResponse,
  PharmacyVerificationStatus,
  PharmacyVerificationSubmitResponse,
} from '@clinic/desktop-bridge-contracts';
import { EVIDENCE_REQUIREMENT_CODE, type EvidenceMediaType } from './evidence-handles';
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

export const ONBOARD_INTENT = 'pharmacy.onboard';
export const OPEN_CASE_INTENT = 'pharmacy.verification.open';
export const SUBMIT_INTENT = 'pharmacy.verification.submit';
export const UPLOAD_CREATE_INTENT = 'pharmacy.verification.upload.create';
export const UPLOAD_COMPLETE_INTENT = 'pharmacy.verification.upload.complete';
export const BRANCH_CREATE_INTENT = 'pharmacy.branch.create';
export const BRANCH_INVITE_INTENT = 'pharmacy.branch.inviteOperator';

export const pharmacyIntentKeys = new IntentKeyStore();

/**
 * Canonical branch-create intent identity. Address and phone are material
 * request fields, so they participate in the SHA-256 fingerprint. The store
 * retains only that digest plus a random UUID Idempotency-Key — never the
 * plaintext fields.
 */
export function pharmacyBranchCreateIntentFingerprint(
  organizationId: string,
  input: Pick<
    PharmacyBranchCreateRequest,
    'publicName' | 'address' | 'countryCode' | 'latitude' | 'longitude' | 'phone'
  >,
): string {
  return pharmacyIntentKeys.fingerprint({
    organizationId,
    publicName: input.publicName,
    address: input.address,
    countryCode: input.countryCode,
    latitude: input.latitude,
    longitude: input.longitude,
    phone: input.phone,
  });
}

type ApiOrganization = {
  organization_id: string;
  public_name: string;
  verification_status: PharmacyOrganizationView['verificationStatus'];
  status: PharmacyOrganizationView['status'];
  version: number;
  created_at: string;
  updated_at: string;
  initial_branch: {
    branch_id: string;
    public_name: string;
    country_code: 'EG';
    status: PharmacyOrganizationView['status'];
    version: number;
  };
  membership: {
    membership_id: string;
    role: 'owner';
    status: PharmacyOrganizationView['membership']['status'];
  };
};

function requirePharmacyAccount(locale: string): Promise<void> {
  const cached = currentAccountType();
  if (cached === 'pharmacy') {
    return Promise.resolve();
  }
  return platformGateway.me(locale).then((me) => {
    if (me.accountType !== 'pharmacy') {
      throw new GatewayError('PERMISSION_DENIED');
    }
  });
}

function mapOrganization(raw: ApiOrganization): PharmacyOrganizationView {
  return {
    organizationId: raw.organization_id,
    publicName: raw.public_name,
    verificationStatus: raw.verification_status,
    status: raw.status,
    version: raw.version,
    createdAt: raw.created_at,
    updatedAt: raw.updated_at,
    initialBranch: {
      branchId: raw.initial_branch.branch_id,
      publicName: raw.initial_branch.public_name,
      countryCode: raw.initial_branch.country_code,
      status: raw.initial_branch.status,
      version: raw.initial_branch.version,
    },
    membership: {
      membershipId: raw.membership.membership_id,
      role: 'owner',
      status: raw.membership.status,
    },
  };
}

function mapUploadStatus(raw: {
  upload_id: string;
  requirement_code: string;
  state: PharmacyUploadStatus['state'];
  rejection_reason: PharmacyUploadStatus['rejectionReason'];
  expires_at: string;
  completed_at: string | null;
}): PharmacyUploadStatus {
  return {
    uploadId: raw.upload_id,
    requirementCode: raw.requirement_code,
    state: raw.state,
    rejectionReason: raw.rejection_reason,
    expiresAt: raw.expires_at,
    completedAt: raw.completed_at,
  };
}

type ApiBranch = {
  branch_id: string;
  public_name: string;
  country_code: 'EG';
  status: PharmacyBranchPrivateView['status'];
  version: number;
  created_at: string;
  updated_at: string;
  address?: string;
  latitude?: number;
  longitude?: number;
};

type ApiMembership = {
  membership_id: string;
  role: 'branch_operator';
  status: PharmacyBranchMembershipView['status'];
  version: number;
  invited_at: string | null;
  accepted_at: string | null;
  revoked_at: string | null;
};

function mapBranch(raw: ApiBranch): PharmacyBranchPrivateView {
  return {
    branchId: raw.branch_id,
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

function mapMembership(raw: ApiMembership): PharmacyBranchMembershipView {
  return {
    membershipId: raw.membership_id,
    role: 'branch_operator',
    status: raw.status,
    version: raw.version,
    invitedAt: raw.invited_at,
    acceptedAt: raw.accepted_at,
    revokedAt: raw.revoked_at,
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

function branchesListPath(organizationId: string, input: PharmacyBranchesListRequest): string {
  const params = new URLSearchParams();
  if (typeof input.cursor === 'string') {
    params.set('cursor', input.cursor);
  }
  if (typeof input.limit === 'number') {
    params.set('limit', String(input.limit));
  }
  const query = params.toString();
  const base = `/api/v1/pharmacy-organizations/${organizationId}/branches`;
  return query === '' ? base : `${base}?${query}`;
}

async function requireOwnOrganizationId(locale: string): Promise<string> {
  await requirePharmacyAccount(locale);
  try {
    const data = await coreJsonRequest<ApiOrganization>(
      'GET',
      '/api/v1/pharmacy-organizations/me',
      locale,
    );
    const organization = mapOrganization(data);
    if (
      organization.verificationStatus !== 'approved' ||
      organization.status !== 'active' ||
      organization.membership.role !== 'owner' ||
      organization.membership.status !== 'active'
    ) {
      throw new GatewayError('PERMISSION_DENIED');
    }
    return organization.organizationId;
  } catch (error) {
    if (error instanceof GatewayError && error.failureCode === 'NOT_FOUND') {
      throw new GatewayError('PERMISSION_DENIED');
    }
    throw error;
  }
}

export const pharmacyGateway = {
  async getOwnOrganization(locale: string): Promise<PharmacyOwnOrganizationResponse> {
    await requirePharmacyAccount(locale);
    try {
      const data = await coreJsonRequest<ApiOrganization>(
        'GET',
        '/api/v1/pharmacy-organizations/me',
        locale,
      );
      return { present: true, organization: mapOrganization(data) };
    } catch (error) {
      if (error instanceof GatewayError && error.failureCode === 'NOT_FOUND') {
        return { present: false };
      }
      throw error;
    }
  },

  async onboard(locale: string, input: PharmacyOnboardRequest): Promise<PharmacyOnboardResponse> {
    await requirePharmacyAccount(locale);
    const fingerprint = pharmacyIntentKeys.fingerprint({
      legalName: input.legalName,
      publicName: input.publicName,
      legalRegistrationIdentifier: input.legalRegistrationIdentifier,
      branchPublicName: input.branchPublicName,
      address: input.address,
      countryCode: input.countryCode,
      latitude: input.latitude,
      longitude: input.longitude,
      phone: input.phone,
    });
    const key = pharmacyIntentKeys.keyFor(ONBOARD_INTENT, fingerprint);
    try {
      const data = await coreJsonRequest<{
        status: 'organization_ready' | 'manual_review_required';
        organization_id?: string;
        branch_id?: string;
        membership_id?: string;
        version?: number;
      }>(
        'POST',
        '/api/v1/pharmacy-organizations/onboarding',
        locale,
        {
          legal_name: input.legalName,
          public_name: input.publicName,
          legal_registration_identifier: input.legalRegistrationIdentifier,
          branch_public_name: input.branchPublicName,
          address: input.address,
          country_code: input.countryCode,
          latitude: input.latitude,
          longitude: input.longitude,
          phone: input.phone,
        },
        { 'Idempotency-Key': key },
      );
      if (data.status === 'manual_review_required') {
        const mapped = { status: 'manual_review_required' as const };
        pharmacyIntentKeys.retireWhenDelivered(ONBOARD_INTENT, fingerprint, key);
        return mapped;
      }
      if (
        typeof data.organization_id !== 'string' ||
        typeof data.branch_id !== 'string' ||
        typeof data.membership_id !== 'string' ||
        typeof data.version !== 'number'
      ) {
        throw new GatewayError('UPSTREAM_FAILED');
      }
      const mapped = {
        status: 'organization_ready' as const,
        organizationId: data.organization_id,
        branchId: data.branch_id,
        membershipId: data.membership_id,
        version: data.version,
      };
      pharmacyIntentKeys.retireWhenDelivered(ONBOARD_INTENT, fingerprint, key);
      return mapped;
    } catch (error) {
      if (
        error instanceof GatewayError &&
        (error.failureCode === 'VALIDATION_FAILED' || error.failureCode === 'PERMISSION_DENIED')
      ) {
        pharmacyIntentKeys.retireWhenDelivered(ONBOARD_INTENT, fingerprint, key);
      }
      throw error;
    }
  },

  async openVerificationCase(locale: string): Promise<PharmacyVerificationOpenResponse> {
    await requirePharmacyAccount(locale);
    const fingerprint = pharmacyIntentKeys.fingerprint({ intent: OPEN_CASE_INTENT });
    const key = pharmacyIntentKeys.keyFor(OPEN_CASE_INTENT, fingerprint);
    try {
      const data = await coreJsonRequest<{
        status: 'ready';
        organization_id: string;
        case_id: string;
        case_status: 'draft' | 'pending_review';
        case_version: number;
        organization_version: number;
      }>(
        'POST',
        '/api/v1/pharmacy-organizations/me/verification-cases',
        locale,
        {},
        { 'Idempotency-Key': key },
      );
      const mapped = {
        status: 'ready' as const,
        organizationId: data.organization_id,
        caseId: data.case_id,
        caseStatus: data.case_status,
        caseVersion: data.case_version,
        organizationVersion: data.organization_version,
      };
      pharmacyIntentKeys.retireWhenDelivered(OPEN_CASE_INTENT, fingerprint, key);
      return mapped;
    } catch (error) {
      throw error;
    }
  },

  async verificationStatus(locale: string): Promise<PharmacyVerificationStatus> {
    await requirePharmacyAccount(locale);
    const data = await coreJsonRequest<{
      applicant_type: 'pharmacy';
      organization_id: string;
      organization_verification_status: PharmacyVerificationStatus['organizationVerificationStatus'];
      organization_status: PharmacyVerificationStatus['organizationStatus'];
      organization_version: number;
      case_id: string | null;
      case_status: PharmacyVerificationStatus['caseStatus'];
      case_version: number | null;
      case_type: 'pharmacy_verification' | null;
      submitted_at: string | null;
      decided_at: string | null;
      decision: PharmacyVerificationStatus['decision'];
      reason_code: string | null;
      documents: Array<{
        document_id: string;
        requirement_code: string;
        scan_status: 'pending' | 'clean' | 'failed';
        status: 'quarantined' | 'available' | 'rejected' | 'retired';
        uploaded_at: string;
      }>;
    }>('GET', '/api/v1/pharmacy-organizations/me/verification-status', locale);

    return {
      applicantType: 'pharmacy',
      organizationId: data.organization_id,
      organizationVerificationStatus: data.organization_verification_status,
      organizationStatus: data.organization_status,
      organizationVersion: data.organization_version,
      caseId: data.case_id,
      caseStatus: data.case_status,
      caseVersion: data.case_version,
      caseType: data.case_type,
      submittedAt: data.submitted_at,
      decidedAt: data.decided_at,
      decision: data.decision,
      reasonCode: data.reason_code,
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
    input: { caseVersion: number; organizationVersion: number },
  ): Promise<PharmacyVerificationSubmitResponse> {
    await requirePharmacyAccount(locale);
    const fingerprint = pharmacyIntentKeys.fingerprint(input);
    const key = pharmacyIntentKeys.keyFor(SUBMIT_INTENT, fingerprint);
    try {
      const data = await coreJsonRequest<{
        status: 'submitted';
        organization_id: string;
        case_id: string;
        case_status: 'pending_review';
        case_version: number;
        organization_version: number;
        organization_verification_status: 'pending_review';
      }>(
        'POST',
        '/api/v1/pharmacy-organizations/me/verification-submissions',
        locale,
        {
          case_version: input.caseVersion,
          organization_version: input.organizationVersion,
        },
        { 'Idempotency-Key': key },
      );
      const mapped = {
        status: 'submitted' as const,
        organizationId: data.organization_id,
        caseId: data.case_id,
        caseStatus: data.case_status,
        caseVersion: data.case_version,
        organizationVersion: data.organization_version,
        organizationVerificationStatus: data.organization_verification_status,
      };
      pharmacyIntentKeys.retireWhenDelivered(SUBMIT_INTENT, fingerprint, key);
      return mapped;
    } catch (error) {
      if (
        error instanceof GatewayError &&
        (error.failureCode === 'VERSION_CONFLICT' || error.failureCode === 'STATE_CONFLICT')
      ) {
        pharmacyIntentKeys.retireWhenDelivered(SUBMIT_INTENT, fingerprint, key);
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
    },
  ): Promise<PharmacyUploadStatus> {
    await requirePharmacyAccount(locale);
    const createFingerprint = pharmacyIntentKeys.fingerprint({
      caseId: input.caseId,
      sizeBytes: input.sizeBytes,
      mediaType: input.candidateMediaType,
      requirement: EVIDENCE_REQUIREMENT_CODE,
    });
    const createKey = pharmacyIntentKeys.keyFor(UPLOAD_CREATE_INTENT, createFingerprint);

    type CreateRaw = {
      upload_id: string;
      requirement_code: string;
      state: PharmacyUploadStatus['state'];
      rejection_reason: PharmacyUploadStatus['rejectionReason'];
      expires_at: string;
      completed_at: string | null;
      upload_target?: unknown;
    };

    let created: CreateRaw;
    try {
      created = await coreJsonRequest<CreateRaw>(
        'POST',
        '/api/v1/verification-uploads',
        locale,
        {
          case_id: input.caseId,
          requirement_code: EVIDENCE_REQUIREMENT_CODE,
          expected_size_bytes: input.sizeBytes,
          declared_media_type: input.candidateMediaType,
        },
        { 'Idempotency-Key': createKey },
      );
    } catch (error) {
      throw error;
    }

    const target = parseIssuedUploadTarget(created.upload_target, app.isPackaged);
    await putIssuedUploadBytes(target.url, target.headers, input.bytes);

    const completeFingerprint = pharmacyIntentKeys.fingerprint({ uploadId: created.upload_id });
    const completeKey = pharmacyIntentKeys.keyFor(UPLOAD_COMPLETE_INTENT, completeFingerprint);
    try {
      const completed = await coreJsonRequest<{
        upload_id: string;
        requirement_code: string;
        state: PharmacyUploadStatus['state'];
        rejection_reason: PharmacyUploadStatus['rejectionReason'];
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
      pharmacyIntentKeys.retireWhenDelivered(UPLOAD_CREATE_INTENT, createFingerprint, createKey);
      pharmacyIntentKeys.retireWhenDelivered(UPLOAD_COMPLETE_INTENT, completeFingerprint, completeKey);
      return mapped;
    } catch (error) {
      throw error;
    }
  },

  async uploadStatus(locale: string, uploadId: string): Promise<PharmacyUploadStatus> {
    await requirePharmacyAccount(locale);
    const data = await coreJsonRequest<{
      upload_id: string;
      requirement_code: string;
      state: PharmacyUploadStatus['state'];
      rejection_reason: PharmacyUploadStatus['rejectionReason'];
      expires_at: string;
      completed_at: string | null;
    }>('GET', `/api/v1/verification-uploads/${uploadId}`, locale);
    return mapUploadStatus(data);
  },

  async listBranches(
    locale: string,
    input: PharmacyBranchesListRequest,
  ): Promise<PharmacyBranchesListResponse> {
    const organizationId = await requireOwnOrganizationId(locale);
    const envelope = await coreJsonRequestEnvelope<ApiBranch[]>(
      'GET',
      branchesListPath(organizationId, input),
      locale,
    );
    const pagination = paginationFromMeta(envelope.meta);
    return {
      branches: envelope.data.map(mapBranch),
      hasMore: pagination.hasMore,
      nextCursor: pagination.nextCursor,
    };
  },

  async createBranch(
    locale: string,
    input: PharmacyBranchCreateRequest,
  ): Promise<PharmacyBranchCreateResponse> {
    const organizationId = await requireOwnOrganizationId(locale);
    const fingerprint = pharmacyBranchCreateIntentFingerprint(organizationId, input);
    const key = pharmacyIntentKeys.keyFor(BRANCH_CREATE_INTENT, fingerprint);
    try {
      const data = await coreJsonRequest<{
        branch_id: string;
        status: 'active';
        version: number;
      }>(
        'POST',
        `/api/v1/pharmacy-organizations/${organizationId}/branches`,
        locale,
        {
          public_name: input.publicName,
          address: input.address,
          country_code: input.countryCode,
          latitude: input.latitude,
          longitude: input.longitude,
          phone: input.phone,
        },
        { 'Idempotency-Key': key },
      );
      const mapped = {
        branchId: data.branch_id,
        status: data.status,
        version: data.version,
      };
      pharmacyIntentKeys.retireWhenDelivered(BRANCH_CREATE_INTENT, fingerprint, key);
      return mapped;
    } catch (error) {
      if (
        error instanceof GatewayError &&
        (error.failureCode === 'VALIDATION_FAILED' || error.failureCode === 'PERMISSION_DENIED')
      ) {
        pharmacyIntentKeys.retireWhenDelivered(BRANCH_CREATE_INTENT, fingerprint, key);
      }
      throw error;
    }
  },

  async getBranch(locale: string, branchId: string): Promise<PharmacyBranchPrivateView> {
    const organizationId = await requireOwnOrganizationId(locale);
    const data = await coreJsonRequest<ApiBranch>(
      'GET',
      `/api/v1/pharmacy-organizations/${organizationId}/branches/${branchId}`,
      locale,
    );
    return mapBranch(data);
  },

  async updateBranch(
    locale: string,
    input: PharmacyBranchUpdateRequest,
  ): Promise<PharmacyBranchPrivateView> {
    const organizationId = await requireOwnOrganizationId(locale);
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
    if (input.phone !== undefined) {
      body['phone'] = input.phone;
    }
    const data = await coreJsonRequest<ApiBranch>(
      'PATCH',
      `/api/v1/pharmacy-organizations/${organizationId}/branches/${input.branchId}`,
      locale,
      body,
    );
    return mapBranch(data);
  },

  async inviteOperator(
    locale: string,
    input: PharmacyBranchInviteOperatorRequest,
  ): Promise<PharmacyBranchInviteOperatorResponse> {
    const organizationId = await requireOwnOrganizationId(locale);
    const fingerprint = pharmacyIntentKeys.fingerprint({
      organizationId,
      branchId: input.branchId,
      phone: input.phone,
    });
    const key = pharmacyIntentKeys.keyFor(BRANCH_INVITE_INTENT, fingerprint);
    try {
      const envelope = await coreJsonRequestEnvelope<{
        invitation_id: string;
        status: 'pending';
        expires_at: string;
      }>(
        'POST',
        `/api/v1/pharmacy-organizations/${organizationId}/branches/${input.branchId}/staff-invitations`,
        locale,
        { phone: input.phone },
        { 'Idempotency-Key': key },
      );
      const mapped = {
        invitationId: envelope.data.invitation_id,
        status: envelope.data.status,
        expiresAt: envelope.data.expires_at,
        existingPending: envelope.status === 200,
      };
      pharmacyIntentKeys.retireWhenDelivered(BRANCH_INVITE_INTENT, fingerprint, key);
      return mapped;
    } catch (error) {
      if (
        error instanceof GatewayError &&
        (error.failureCode === 'VALIDATION_FAILED' || error.failureCode === 'PERMISSION_DENIED')
      ) {
        pharmacyIntentKeys.retireWhenDelivered(BRANCH_INVITE_INTENT, fingerprint, key);
      }
      throw error;
    }
  },

  async listMemberships(locale: string, branchId: string): Promise<PharmacyBranchMembershipsResponse> {
    const organizationId = await requireOwnOrganizationId(locale);
    const data = await coreJsonRequest<ApiMembership[]>(
      'GET',
      `/api/v1/pharmacy-organizations/${organizationId}/branches/${branchId}/memberships`,
      locale,
    );
    return { memberships: data.map(mapMembership) };
  },

  async revokeMembership(
    locale: string,
    input: PharmacyBranchRevokeMembershipRequest,
  ): Promise<PharmacyBranchMembershipView> {
    const organizationId = await requireOwnOrganizationId(locale);
    const data = await coreJsonRequest<ApiMembership>(
      'DELETE',
      `/api/v1/pharmacy-organizations/${organizationId}/branches/${input.branchId}/memberships/${input.membershipId}`,
      locale,
    );
    return mapMembership(data);
  },

  clearIntents(): void {
    pharmacyIntentKeys.clearAll();
  },
};

export function resetPharmacyGatewayState(): void {
  pharmacyIntentKeys.clearAll();
}
