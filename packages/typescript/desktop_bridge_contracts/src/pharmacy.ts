/**
 * Pharmacy-desktop-only IPC schemas.
 *
 * This file is schemas and types only: no Electron, no Node, no transport.
 * Executable capability registration lives in `apps/pharmacy-desktop`. The
 * Doctor desktop must not import these channel names into its registry.
 */

import { z } from 'zod';

const DEFAULT_IPC_TIMEOUT_MS = 15_000;
const emptyRequestSchema = z.object({}).strict();

/** Purpose-bound pharmacy domain channels. Never added to the shared ALL_CHANNELS set. */
export const PHARMACY_CHANNELS = {
  organizationGetOwn: 'clinic:pharmacy.organization.getOwn',
  organizationOnboard: 'clinic:pharmacy.organization.onboard',
  verificationOpenCase: 'clinic:pharmacy.verification.openCase',
  verificationStatus: 'clinic:pharmacy.verification.status',
  verificationSubmit: 'clinic:pharmacy.verification.submit',
  evidenceSelect: 'clinic:pharmacy.evidence.select',
  evidenceClear: 'clinic:pharmacy.evidence.clear',
  evidenceUpload: 'clinic:pharmacy.evidence.upload',
  uploadStatus: 'clinic:pharmacy.upload.status',
  branchesList: 'clinic:pharmacy.branches.list',
  branchCreate: 'clinic:pharmacy.branch.create',
  branchGet: 'clinic:pharmacy.branch.get',
  branchUpdate: 'clinic:pharmacy.branch.update',
  branchInviteOperator: 'clinic:pharmacy.branch.inviteOperator',
  branchMemberships: 'clinic:pharmacy.branch.memberships',
  branchRevokeMembership: 'clinic:pharmacy.branch.revokeMembership',
} as const;

export type PharmacyChannelName = (typeof PHARMACY_CHANNELS)[keyof typeof PHARMACY_CHANNELS];

export const PHARMACY_CHANNEL_LIST: readonly PharmacyChannelName[] = Object.values(PHARMACY_CHANNELS);

export const pharmacyMediaTypeSchema = z.enum(['application/pdf', 'image/jpeg', 'image/png']);

export const pharmacyOrganizationStatusSchema = z.enum([
  'draft',
  'pending',
  'active',
  'suspended',
  'closed',
]);

export const pharmacyVerificationStatusSchema = z.enum([
  'draft',
  'pending_review',
  'changes_requested',
  'approved',
  'rejected',
  'suspended',
]);

export const pharmacyMembershipStatusSchema = z.enum(['pending', 'active', 'suspended', 'revoked']);

export const pharmacyCaseStatusSchema = z.enum([
  'draft',
  'pending_review',
  'changes_requested',
  'approved',
  'rejected',
]);

export const pharmacyUploadStateSchema = z.enum([
  'requested',
  'uploading',
  'quarantined',
  'validating',
  'scanning',
  'available',
  'rejected',
]);

export const pharmacyBranchViewSchema = z
  .object({
    branchId: z.string().uuid(),
    publicName: z.string().max(200),
    countryCode: z.literal('EG'),
    status: pharmacyOrganizationStatusSchema,
    version: z.number().int().nonnegative(),
  })
  .strict();

export const pharmacyMembershipViewSchema = z
  .object({
    membershipId: z.string().uuid(),
    role: z.literal('owner'),
    status: pharmacyMembershipStatusSchema,
  })
  .strict();

export const pharmacyOrganizationViewSchema = z
  .object({
    organizationId: z.string().uuid(),
    publicName: z.string().max(200),
    verificationStatus: pharmacyVerificationStatusSchema,
    status: pharmacyOrganizationStatusSchema,
    version: z.number().int().nonnegative(),
    createdAt: z.string().max(64),
    updatedAt: z.string().max(64),
    initialBranch: pharmacyBranchViewSchema,
    membership: pharmacyMembershipViewSchema,
  })
  .strict();

export type PharmacyOrganizationView = z.infer<typeof pharmacyOrganizationViewSchema>;

export const pharmacyOwnOrganizationResponseSchema = z.discriminatedUnion('present', [
  z.object({ present: z.literal(false) }).strict(),
  z
    .object({
      present: z.literal(true),
      organization: pharmacyOrganizationViewSchema,
    })
    .strict(),
]);

export type PharmacyOwnOrganizationResponse = z.infer<typeof pharmacyOwnOrganizationResponseSchema>;

export const pharmacyOnboardRequestSchema = z
  .object({
    legalName: z.string().min(1).max(200),
    publicName: z.string().min(1).max(200),
    legalRegistrationIdentifier: z.string().min(1).max(64),
    branchPublicName: z.string().min(1).max(200),
    address: z.string().min(1).max(500),
    countryCode: z.literal('EG'),
    latitude: z.number().gte(-90).lte(90),
    longitude: z.number().gte(-180).lte(180),
    phone: z.string().min(8).max(32),
  })
  .strict();

export type PharmacyOnboardRequest = z.infer<typeof pharmacyOnboardRequestSchema>;

export const pharmacyOnboardResponseSchema = z.discriminatedUnion('status', [
  z
    .object({
      status: z.literal('organization_ready'),
      organizationId: z.string().uuid(),
      branchId: z.string().uuid(),
      membershipId: z.string().uuid(),
      version: z.number().int().nonnegative(),
    })
    .strict(),
  z.object({ status: z.literal('manual_review_required') }).strict(),
]);

export type PharmacyOnboardResponse = z.infer<typeof pharmacyOnboardResponseSchema>;

export const pharmacyVerificationDocumentSchema = z
  .object({
    documentId: z.string().uuid(),
    requirementCode: z.string().max(64),
    scanStatus: z.enum(['pending', 'clean', 'failed']),
    status: z.enum(['quarantined', 'available', 'rejected', 'retired']),
    uploadedAt: z.string().max(64),
  })
  .strict();

export const pharmacyVerificationStatusResponseSchema = z
  .object({
    applicantType: z.literal('pharmacy'),
    organizationId: z.string().uuid(),
    organizationVerificationStatus: pharmacyVerificationStatusSchema,
    organizationStatus: pharmacyOrganizationStatusSchema,
    organizationVersion: z.number().int().nonnegative(),
    caseId: z.string().uuid().nullable(),
    caseStatus: pharmacyCaseStatusSchema.nullable(),
    caseVersion: z.number().int().nonnegative().nullable(),
    caseType: z.literal('pharmacy_verification').nullable(),
    submittedAt: z.string().max(64).nullable(),
    decidedAt: z.string().max(64).nullable(),
    decision: z.enum(['approved', 'rejected', 'changes_requested']).nullable(),
    reasonCode: z.string().max(64).nullable(),
    documents: z.array(pharmacyVerificationDocumentSchema).max(16),
  })
  .strict();

export type PharmacyVerificationStatus = z.infer<typeof pharmacyVerificationStatusResponseSchema>;

export const pharmacyVerificationOpenResponseSchema = z
  .object({
    status: z.literal('ready'),
    organizationId: z.string().uuid(),
    caseId: z.string().uuid(),
    caseStatus: z.enum(['draft', 'pending_review']),
    caseVersion: z.number().int().nonnegative(),
    organizationVersion: z.number().int().nonnegative(),
  })
  .strict();

export type PharmacyVerificationOpenResponse = z.infer<typeof pharmacyVerificationOpenResponseSchema>;

export const pharmacyVerificationSubmitRequestSchema = z
  .object({
    caseVersion: z.number().int().nonnegative(),
    organizationVersion: z.number().int().nonnegative(),
  })
  .strict();

export const pharmacyVerificationSubmitResponseSchema = z
  .object({
    status: z.literal('submitted'),
    organizationId: z.string().uuid(),
    caseId: z.string().uuid(),
    caseStatus: z.literal('pending_review'),
    caseVersion: z.number().int().nonnegative(),
    organizationVersion: z.number().int().nonnegative(),
    organizationVerificationStatus: z.literal('pending_review'),
  })
  .strict();

export type PharmacyVerificationSubmitResponse = z.infer<typeof pharmacyVerificationSubmitResponseSchema>;

export const pharmacyEvidenceHandleIdSchema = z
  .string()
  .regex(/^[A-Za-z0-9_-]{32,64}$/, 'must be an opaque evidence handle');

export const pharmacyEvidenceSelectResponseSchema = z.discriminatedUnion('selected', [
  z.object({ selected: z.literal(false) }).strict(),
  z
    .object({
      selected: z.literal(true),
      handleId: pharmacyEvidenceHandleIdSchema,
      displayName: z.string().min(1).max(255),
      sizeBytes: z.number().int().positive().max(20 * 1024 * 1024),
      candidateMediaType: pharmacyMediaTypeSchema,
    })
    .strict(),
]);

export type PharmacyEvidenceSelectResponse = z.infer<typeof pharmacyEvidenceSelectResponseSchema>;

export const pharmacyEvidenceClearRequestSchema = z
  .object({
    handleId: pharmacyEvidenceHandleIdSchema,
  })
  .strict();

export const pharmacyEvidenceClearResponseSchema = z
  .object({
    cleared: z.literal(true),
  })
  .strict();

export const pharmacyEvidenceUploadRequestSchema = z
  .object({
    handleId: pharmacyEvidenceHandleIdSchema,
    caseId: z.string().uuid(),
  })
  .strict();

export const pharmacyUploadStatusResponseSchema = z
  .object({
    uploadId: z.string().uuid(),
    requirementCode: z.string().max(64),
    state: pharmacyUploadStateSchema,
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

export type PharmacyUploadStatus = z.infer<typeof pharmacyUploadStatusResponseSchema>;

export const pharmacyUploadStatusRequestSchema = z
  .object({
    uploadId: z.string().uuid(),
  })
  .strict();

export const pharmacyBranchLatitudeSchema = z.number().finite().gte(-90).lte(90);
export const pharmacyBranchLongitudeSchema = z.number().finite().gte(-180).lte(180);

/**
 * Owner-private branch projection. Phone is never present. organization_id
 * stays in Core and is not exposed to the renderer.
 */
export const pharmacyBranchPrivateViewSchema = z
  .object({
    branchId: z.string().uuid(),
    publicName: z.string().min(1).max(200),
    countryCode: z.literal('EG'),
    status: pharmacyOrganizationStatusSchema,
    version: z.number().int().positive(),
    createdAt: z.string().max(64),
    updatedAt: z.string().max(64),
    address: z.string().min(1).max(500).optional(),
    latitude: pharmacyBranchLatitudeSchema.optional(),
    longitude: pharmacyBranchLongitudeSchema.optional(),
  })
  .strict();

export type PharmacyBranchPrivateView = z.infer<typeof pharmacyBranchPrivateViewSchema>;

export const pharmacyBranchesListRequestSchema = z
  .object({
    cursor: z.string().min(1).max(4096).optional(),
    limit: z.number().int().min(1).max(100).optional(),
  })
  .strict();

export type PharmacyBranchesListRequest = z.infer<typeof pharmacyBranchesListRequestSchema>;

export const pharmacyBranchesListResponseSchema = z
  .object({
    branches: z.array(pharmacyBranchPrivateViewSchema).max(100),
    hasMore: z.boolean(),
    nextCursor: z.string().min(1).max(4096).nullable(),
  })
  .strict();

export type PharmacyBranchesListResponse = z.infer<typeof pharmacyBranchesListResponseSchema>;

export const pharmacyBranchCreateRequestSchema = z
  .object({
    publicName: z.string().min(1).max(200),
    address: z.string().min(1).max(500),
    countryCode: z.literal('EG'),
    latitude: pharmacyBranchLatitudeSchema,
    longitude: pharmacyBranchLongitudeSchema,
    phone: z.string().min(8).max(32),
  })
  .strict();

export type PharmacyBranchCreateRequest = z.infer<typeof pharmacyBranchCreateRequestSchema>;

export const pharmacyBranchCreateResponseSchema = z
  .object({
    branchId: z.string().uuid(),
    status: z.literal('active'),
    version: z.number().int().positive(),
  })
  .strict();

export type PharmacyBranchCreateResponse = z.infer<typeof pharmacyBranchCreateResponseSchema>;

export const pharmacyBranchGetRequestSchema = z
  .object({
    branchId: z.string().uuid(),
  })
  .strict();

export const pharmacyBranchUpdateRequestSchema = z
  .object({
    branchId: z.string().uuid(),
    expectedVersion: z.number().int().positive(),
    publicName: z.string().min(1).max(200).optional(),
    address: z.string().min(1).max(500).optional(),
    countryCode: z.literal('EG').optional(),
    latitude: pharmacyBranchLatitudeSchema.optional(),
    longitude: pharmacyBranchLongitudeSchema.optional(),
    phone: z.string().min(8).max(32).optional(),
  })
  .strict();

export type PharmacyBranchUpdateRequest = z.infer<typeof pharmacyBranchUpdateRequestSchema>;

export const pharmacyBranchInviteOperatorRequestSchema = z
  .object({
    branchId: z.string().uuid(),
    phone: z.string().min(8).max(32),
  })
  .strict();

export type PharmacyBranchInviteOperatorRequest = z.infer<typeof pharmacyBranchInviteOperatorRequestSchema>;

export const pharmacyBranchInviteOperatorResponseSchema = z
  .object({
    invitationId: z.string().uuid(),
    status: z.literal('pending'),
    expiresAt: z.string().max(64),
    existingPending: z.boolean(),
  })
  .strict();

export type PharmacyBranchInviteOperatorResponse = z.infer<
  typeof pharmacyBranchInviteOperatorResponseSchema
>;

export const pharmacyBranchMembershipViewSchema = z
  .object({
    membershipId: z.string().uuid(),
    role: z.literal('branch_operator'),
    status: pharmacyMembershipStatusSchema,
    version: z.number().int().positive(),
    invitedAt: z.string().max(64).nullable(),
    acceptedAt: z.string().max(64).nullable(),
    revokedAt: z.string().max(64).nullable(),
  })
  .strict();

export type PharmacyBranchMembershipView = z.infer<typeof pharmacyBranchMembershipViewSchema>;

export const pharmacyBranchMembershipsRequestSchema = z
  .object({
    branchId: z.string().uuid(),
  })
  .strict();

export const pharmacyBranchMembershipsResponseSchema = z
  .object({
    memberships: z.array(pharmacyBranchMembershipViewSchema).max(200),
  })
  .strict();

export type PharmacyBranchMembershipsResponse = z.infer<typeof pharmacyBranchMembershipsResponseSchema>;

export const pharmacyBranchRevokeMembershipRequestSchema = z
  .object({
    branchId: z.string().uuid(),
    membershipId: z.string().uuid(),
  })
  .strict();

export type PharmacyBranchRevokeMembershipRequest = z.infer<
  typeof pharmacyBranchRevokeMembershipRequestSchema
>;

const FILE_DIALOG_TIMEOUT_MS = 300_000;
const UPLOAD_TIMEOUT_MS = 120_000;

export const PHARMACY_CAPABILITY_REGISTRY = {
  [PHARMACY_CHANNELS.organizationGetOwn]: {
    request: emptyRequestSchema,
    response: pharmacyOwnOrganizationResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.organizationOnboard]: {
    request: pharmacyOnboardRequestSchema,
    response: pharmacyOnboardResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.verificationOpenCase]: {
    request: emptyRequestSchema,
    response: pharmacyVerificationOpenResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.verificationStatus]: {
    request: emptyRequestSchema,
    response: pharmacyVerificationStatusResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.verificationSubmit]: {
    request: pharmacyVerificationSubmitRequestSchema,
    response: pharmacyVerificationSubmitResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.evidenceSelect]: {
    request: emptyRequestSchema,
    response: pharmacyEvidenceSelectResponseSchema,
    timeoutMs: FILE_DIALOG_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.evidenceClear]: {
    request: pharmacyEvidenceClearRequestSchema,
    response: pharmacyEvidenceClearResponseSchema,
    timeoutMs: 1_000,
  },
  [PHARMACY_CHANNELS.evidenceUpload]: {
    request: pharmacyEvidenceUploadRequestSchema,
    response: pharmacyUploadStatusResponseSchema,
    timeoutMs: UPLOAD_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.uploadStatus]: {
    request: pharmacyUploadStatusRequestSchema,
    response: pharmacyUploadStatusResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.branchesList]: {
    request: pharmacyBranchesListRequestSchema,
    response: pharmacyBranchesListResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.branchCreate]: {
    request: pharmacyBranchCreateRequestSchema,
    response: pharmacyBranchCreateResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.branchGet]: {
    request: pharmacyBranchGetRequestSchema,
    response: pharmacyBranchPrivateViewSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.branchUpdate]: {
    request: pharmacyBranchUpdateRequestSchema,
    response: pharmacyBranchPrivateViewSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.branchInviteOperator]: {
    request: pharmacyBranchInviteOperatorRequestSchema,
    response: pharmacyBranchInviteOperatorResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.branchMemberships]: {
    request: pharmacyBranchMembershipsRequestSchema,
    response: pharmacyBranchMembershipsResponseSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
  [PHARMACY_CHANNELS.branchRevokeMembership]: {
    request: pharmacyBranchRevokeMembershipRequestSchema,
    response: pharmacyBranchMembershipViewSchema,
    timeoutMs: DEFAULT_IPC_TIMEOUT_MS,
  },
} as const satisfies Record<
  PharmacyChannelName,
  { request: z.ZodTypeAny; response: z.ZodTypeAny; timeoutMs: number }
>;

