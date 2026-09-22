/**
 * Doctor-desktop-only IPC schemas.
 *
 * This file is schemas and types only: no Electron, no Node, no transport.
 * Executable capability registration lives in `apps/doctor-desktop`. The
 * Pharmacy desktop must not import these channel names into its registry.
 */

import { z } from 'zod';

const DEFAULT_IPC_TIMEOUT_MS = 15_000;
const emptyRequestSchema = z.object({}).strict();

/** Purpose-bound doctor domain channels. Never added to the shared ALL_CHANNELS set. */
export const DOCTOR_CHANNELS = {
  profileGetOwn: 'clinic:doctor.profile.getOwn',
  specialtiesList: 'clinic:doctor.specialties.list',
  profileOnboard: 'clinic:doctor.profile.onboard',
  verificationOpenCase: 'clinic:doctor.verification.openCase',
  verificationStatus: 'clinic:doctor.verification.status',
  verificationSubmit: 'clinic:doctor.verification.submit',
  evidenceSelect: 'clinic:doctor.evidence.select',
  evidenceClear: 'clinic:doctor.evidence.clear',
  evidenceUpload: 'clinic:doctor.evidence.upload',
  uploadStatus: 'clinic:doctor.upload.status',
  locationsList: 'clinic:doctor.locations.list',
  locationsCreate: 'clinic:doctor.locations.create',
  locationsGet: 'clinic:doctor.locations.get',
  locationsUpdate: 'clinic:doctor.locations.update',
  locationsInviteStaff: 'clinic:doctor.locations.inviteStaff',
  locationsMemberships: 'clinic:doctor.locations.memberships',
  locationsRevokeMembership: 'clinic:doctor.locations.revokeMembership',
} as const;

export type DoctorChannelName = (typeof DOCTOR_CHANNELS)[keyof typeof DOCTOR_CHANNELS];

export const DOCTOR_CHANNEL_LIST: readonly DoctorChannelName[] = Object.values(DOCTOR_CHANNELS);

export const doctorMediaTypeSchema = z.enum(['application/pdf', 'image/jpeg', 'image/png']);

export const doctorVerificationStatusSchema = z.enum([
  'draft',
  'pending_review',
  'changes_requested',
  'approved',
  'rejected',
  'suspended',
]);

export const doctorPublicStatusSchema = z.enum(['hidden', 'listed']);

export const doctorCaseStatusSchema = z.enum([
  'draft',
  'pending_review',
  'changes_requested',
  'approved',
  'rejected',
]);

export const doctorUploadStateSchema = z.enum([
  'requested',
  'uploading',
  'quarantined',
  'validating',
  'scanning',
  'available',
  'rejected',
]);

export const doctorSpecialtyViewSchema = z
  .object({
    specialtyId: z.string().uuid(),
    code: z.string().max(64),
    labelAr: z.string().max(200),
    labelEn: z.string().max(200),
    sortOrder: z.number().int().nonnegative(),
  })
  .strict();

export type DoctorSpecialtyView = z.infer<typeof doctorSpecialtyViewSchema>;

export const doctorSpecialtiesResponseSchema = z
  .object({
    specialties: z.array(doctorSpecialtyViewSchema).max(200),
  })
  .strict();

export type DoctorSpecialtiesResponse = z.infer<typeof doctorSpecialtiesResponseSchema>;

export const doctorProfileViewSchema = z
  .object({
    doctorId: z.string().uuid(),
    professionalDisplayName: z.string().max(200),
    specialtyId: z.string().uuid(),
    specialtyCode: z.string().max(64),
    specialtyLabelAr: z.string().max(200),
    specialtyLabelEn: z.string().max(200),
    verificationStatus: doctorVerificationStatusSchema,
    publicStatus: doctorPublicStatusSchema,
    version: z.number().int().nonnegative(),
    approvedAt: z.string().max(64).nullable(),
    suspendedAt: z.string().max(64).nullable(),
    createdAt: z.string().max(64),
    updatedAt: z.string().max(64),
  })
  .strict();

export type DoctorProfileView = z.infer<typeof doctorProfileViewSchema>;

export const doctorOwnProfileResponseSchema = z.discriminatedUnion('present', [
  z.object({ present: z.literal(false) }).strict(),
  z
    .object({
      present: z.literal(true),
      profile: doctorProfileViewSchema,
    })
    .strict(),
]);

export type DoctorOwnProfileResponse = z.infer<typeof doctorOwnProfileResponseSchema>;

export const doctorOnboardRequestSchema = z
  .object({
    nationalId: z.string().min(1).max(32),
    professionalDisplayName: z.string().min(1).max(200),
    specialtyId: z.string().uuid(),
    syndicateNumber: z.string().min(1).max(64).nullable(),
  })
  .strict();

export type DoctorOnboardRequest = z.infer<typeof doctorOnboardRequestSchema>;

export const doctorOnboardResponseSchema = z.discriminatedUnion('status', [
  z
    .object({
      status: z.literal('profile_ready'),
      doctorId: z.string().uuid(),
      version: z.number().int().nonnegative(),
    })
    .strict(),
  z.object({ status: z.literal('manual_review_required') }).strict(),
]);

export type DoctorOnboardResponse = z.infer<typeof doctorOnboardResponseSchema>;

export const doctorVerificationDocumentSchema = z
  .object({
    documentId: z.string().uuid(),
    requirementCode: z.string().max(64),
    scanStatus: z.enum(['pending', 'clean', 'failed']),
    status: z.enum(['quarantined', 'available', 'rejected', 'retired']),
    uploadedAt: z.string().max(64),
  })
  .strict();

export const doctorVerificationStatusResponseSchema = z
  .object({
    applicantType: z.literal('doctor'),
    doctorId: z.string().uuid(),
    profileVerificationStatus: doctorVerificationStatusSchema,
    profilePublicStatus: doctorPublicStatusSchema,
    profileVersion: z.number().int().nonnegative(),
    caseId: z.string().uuid().nullable(),
    caseStatus: doctorCaseStatusSchema.nullable(),
    caseVersion: z.number().int().nonnegative().nullable(),
    caseType: z.literal('doctor_verification').nullable(),
    submittedAt: z.string().max(64).nullable(),
    decidedAt: z.string().max(64).nullable(),
    decision: z.enum(['approved', 'rejected', 'changes_requested']).nullable(),
    reasonCode: z.string().max(64).nullable(),
    documents: z.array(doctorVerificationDocumentSchema).max(16),
  })
  .strict();

export type DoctorVerificationStatus = z.infer<typeof doctorVerificationStatusResponseSchema>;

export const doctorVerificationOpenResponseSchema = z
  .object({
    status: z.literal('ready'),
    doctorId: z.string().uuid(),
    caseId: z.string().uuid(),
    caseStatus: z.enum(['draft', 'pending_review']),
    caseVersion: z.number().int().nonnegative(),
    profileVersion: z.number().int().nonnegative(),
  })
  .strict();

export type DoctorVerificationOpenResponse = z.infer<typeof doctorVerificationOpenResponseSchema>;

export const doctorVerificationSubmitRequestSchema = z
  .object({
    caseVersion: z.number().int().nonnegative(),
    profileVersion: z.number().int().nonnegative(),
  })
  .strict();

export const doctorVerificationSubmitResponseSchema = z
  .object({
    status: z.literal('submitted'),
    doctorId: z.string().uuid(),
    caseId: z.string().uuid(),
    caseStatus: z.literal('pending_review'),
    caseVersion: z.number().int().nonnegative(),
    profileVersion: z.number().int().nonnegative(),
    profileVerificationStatus: z.literal('pending_review'),
  })
  .strict();

export type DoctorVerificationSubmitResponse = z.infer<typeof doctorVerificationSubmitResponseSchema>;

export const doctorEvidenceHandleIdSchema = z
  .string()
  .regex(/^[A-Za-z0-9_-]{32,64}$/, 'must be an opaque evidence handle');

export const doctorEvidenceSelectResponseSchema = z.discriminatedUnion('selected', [
  z.object({ selected: z.literal(false) }).strict(),
  z
    .object({
      selected: z.literal(true),
      handleId: doctorEvidenceHandleIdSchema,
      displayName: z.string().min(1).max(255),
      sizeBytes: z.number().int().positive().max(20 * 1024 * 1024),
      candidateMediaType: doctorMediaTypeSchema,
    })
    .strict(),
]);

export type DoctorEvidenceSelectResponse = z.infer<typeof doctorEvidenceSelectResponseSchema>;

export const doctorEvidenceClearRequestSchema = z
  .object({
    handleId: doctorEvidenceHandleIdSchema,
  })
  .strict();

export const doctorEvidenceClearResponseSchema = z
  .object({
    cleared: z.literal(true),
  })
  .strict();

export const doctorEvidenceUploadRequestSchema = z
  .object({
    handleId: doctorEvidenceHandleIdSchema,
    caseId: z.string().uuid(),
  })
  .strict();

export const doctorUploadStatusResponseSchema = z
  .object({
    uploadId: z.string().uuid(),
    requirementCode: z.string().max(64),
    state: doctorUploadStateSchema,
    rejectionReason: z
      .enum([
        'expired',
        'object_missing',
        'zero_byte',
        'oversized',
        'mime_mismatch',
        'unsupported_format',
        'malformed',
        'malware_detected',
        'toctou_mismatch',
        'case_not_draft',
        'processing_failed',
      ])
      .nullable(),
    expiresAt: z.string().max(64),
    completedAt: z.string().max(64).nullable(),
  })
  .strict();

export type DoctorUploadStatus = z.infer<typeof doctorUploadStatusResponseSchema>;

export const doctorUploadStatusRequestSchema = z
  .object({
    uploadId: z.string().uuid(),
  })
  .strict();

export const doctorClinicCountryCodeSchema = z.literal('EG');

export const doctorClinicLocationStatusSchema = z.enum([
  'draft',
  'pending',
  'active',
  'suspended',
  'closed',
]);

export const doctorClinicMembershipRoleSchema = z.enum(['doctor', 'secretary']);

export const doctorClinicMembershipStatusSchema = z.enum([
  'pending',
  'active',
  'suspended',
  'revoked',
]);

export const doctorClinicLatitudeSchema = z.number().finite().gte(-90).lte(90);
export const doctorClinicLongitudeSchema = z.number().finite().gte(-180).lte(180);

export const doctorClinicLocationViewSchema = z
  .object({
    locationId: z.string().uuid(),
    publicName: z.string().min(1).max(200),
    countryCode: doctorClinicCountryCodeSchema,
    status: doctorClinicLocationStatusSchema,
    version: z.number().int().positive(),
    createdAt: z.string().max(64),
    updatedAt: z.string().max(64),
    address: z.string().min(1).max(500).optional(),
    latitude: doctorClinicLatitudeSchema.optional(),
    longitude: doctorClinicLongitudeSchema.optional(),
  })
  .strict();

export type DoctorClinicLocationView = z.infer<typeof doctorClinicLocationViewSchema>;

export const doctorClinicLocationsListRequestSchema = z
  .object({
    cursor: z.string().min(1).max(4096).optional(),
    limit: z.number().int().min(1).max(100).optional(),
  })
  .strict();

export type DoctorClinicLocationsListRequest = z.infer<typeof doctorClinicLocationsListRequestSchema>;

export const doctorClinicLocationsListResponseSchema = z
  .object({
    locations: z.array(doctorClinicLocationViewSchema).max(100),
    hasMore: z.boolean(),
    nextCursor: z.string().min(1).max(4096).nullable(),
  })
  .strict();

export type DoctorClinicLocationsListResponse = z.infer<typeof doctorClinicLocationsListResponseSchema>;

export const doctorClinicLocationCreateRequestSchema = z
  .object({
    publicName: z.string().min(1).max(200),
    address: z.string().min(1).max(500),
    countryCode: doctorClinicCountryCodeSchema,
    latitude: doctorClinicLatitudeSchema,
    longitude: doctorClinicLongitudeSchema,
  })
  .strict();

export type DoctorClinicLocationCreateRequest = z.infer<typeof doctorClinicLocationCreateRequestSchema>;

export const doctorClinicLocationCreateResponseSchema = z
  .object({
    locationId: z.string().uuid(),
    status: z.literal('active'),
    version: z.number().int().positive(),
  })
  .strict();

export type DoctorClinicLocationCreateResponse = z.infer<typeof doctorClinicLocationCreateResponseSchema>;

export const doctorClinicLocationGetRequestSchema = z
  .object({
    locationId: z.string().uuid(),
  })
  .strict();

export const doctorClinicLocationUpdateRequestSchema = z
  .object({
    locationId: z.string().uuid(),
    expectedVersion: z.number().int().positive(),
    publicName: z.string().min(1).max(200).optional(),
    address: z.string().min(1).max(500).optional(),
    countryCode: doctorClinicCountryCodeSchema.optional(),
    latitude: doctorClinicLatitudeSchema.optional(),
    longitude: doctorClinicLongitudeSchema.optional(),
  })
  .strict();

export type DoctorClinicLocationUpdateRequest = z.infer<typeof doctorClinicLocationUpdateRequestSchema>;

export const doctorClinicInviteStaffRequestSchema = z
  .object({
    locationId: z.string().uuid(),
    phone: z.string().min(8).max(32),
  })
  .strict();

export type DoctorClinicInviteStaffRequest = z.infer<typeof doctorClinicInviteStaffRequestSchema>;

export const doctorClinicInviteStaffResponseSchema = z
  .object({
    invitationId: z.string().uuid(),
    locationId: z.string().uuid(),
    status: z.literal('pending'),
    expiresAt: z.string().max(64),
    existingPending: z.boolean(),
  })
  .strict();

export type DoctorClinicInviteStaffResponse = z.infer<typeof doctorClinicInviteStaffResponseSchema>;

export const doctorClinicMembershipViewSchema = z
  .object({
    membershipId: z.string().uuid(),
    role: doctorClinicMembershipRoleSchema,
    status: doctorClinicMembershipStatusSchema,
    version: z.number().int().positive(),
    invitedAt: z.string().max(64).nullable(),
    acceptedAt: z.string().max(64).nullable(),
    revokedAt: z.string().max(64).nullable(),
  })
  .strict();

export type DoctorClinicMembershipView = z.infer<typeof doctorClinicMembershipViewSchema>;

export const doctorClinicMembershipsRequestSchema = z
  .object({
    locationId: z.string().uuid(),
  })
  .strict();

export const doctorClinicMembershipsResponseSchema = z
  .object({
    memberships: z.array(doctorClinicMembershipViewSchema).max(200),
  })
  .strict();

export type DoctorClinicMembershipsResponse = z.infer<typeof doctorClinicMembershipsResponseSchema>;

export const doctorClinicRevokeMembershipRequestSchema = z
  .object({
    locationId: z.string().uuid(),
    membershipId: z.string().uuid(),
  })
  .strict();

export type DoctorClinicRevokeMembershipRequest = z.infer<typeof doctorClinicRevokeMembershipRequestSchema>;

const FILE_DIALOG_TIMEOUT_MS = 300_000;
const UPLOAD_TIMEOUT_MS = 120_000;

export const DOCTOR_CAPABILITY_REGISTRY = {
  [DOCTOR_CHANNELS.profileGetOwn]: {
    request: emptyRequestSchema,
    response: doctorOwnProfileResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.specialtiesList]: {
    request: emptyRequestSchema,
    response: doctorSpecialtiesResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.profileOnboard]: {
    request: doctorOnboardRequestSchema,
    response: doctorOnboardResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.verificationOpenCase]: {
    request: emptyRequestSchema,
    response: doctorVerificationOpenResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.verificationStatus]: {
    request: emptyRequestSchema,
    response: doctorVerificationStatusResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.verificationSubmit]: {
    request: doctorVerificationSubmitRequestSchema,
    response: doctorVerificationSubmitResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.evidenceSelect]: {
    request: emptyRequestSchema,
    response: doctorEvidenceSelectResponseSchema,
    timeoutMs: FILE_DIALOG_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.evidenceClear]: {
    request: doctorEvidenceClearRequestSchema,
    response: doctorEvidenceClearResponseSchema,
    timeoutMs: 1_000,
  },
  [DOCTOR_CHANNELS.evidenceUpload]: {
    request: doctorEvidenceUploadRequestSchema,
    response: doctorUploadStatusResponseSchema,
    timeoutMs: UPLOAD_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.uploadStatus]: {
    request: doctorUploadStatusRequestSchema,
    response: doctorUploadStatusResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.locationsList]: {
    request: doctorClinicLocationsListRequestSchema,
    response: doctorClinicLocationsListResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.locationsCreate]: {
    request: doctorClinicLocationCreateRequestSchema,
    response: doctorClinicLocationCreateResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.locationsGet]: {
    request: doctorClinicLocationGetRequestSchema,
    response: doctorClinicLocationViewSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.locationsUpdate]: {
    request: doctorClinicLocationUpdateRequestSchema,
    response: doctorClinicLocationViewSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.locationsInviteStaff]: {
    request: doctorClinicInviteStaffRequestSchema,
    response: doctorClinicInviteStaffResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.locationsMemberships]: {
    request: doctorClinicMembershipsRequestSchema,
    response: doctorClinicMembershipsResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [DOCTOR_CHANNELS.locationsRevokeMembership]: {
    request: doctorClinicRevokeMembershipRequestSchema,
    response: doctorClinicMembershipViewSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
} as const satisfies Record<
  DoctorChannelName,
  { request: z.ZodTypeAny; response: z.ZodTypeAny; timeoutMs: number }
>;
