import { describe, expect, it } from 'vitest';
import {
  ALL_CHANNELS,
  BRIDGE_CONTRACT_VERSION,
  DOCTOR_ALL_CHANNELS,
  DOCTOR_CHANNELS,
  MAX_IPC_PAYLOAD_BYTES,
  PHARMACY_ALL_CHANNELS,
  PHARMACY_CHANNELS,
  bridgeErrorSchema,
  doctorClinicLocationViewSchema,
  doctorClinicLocationCreateRequestSchema,
  doctorClinicLocationUpdateRequestSchema,
  doctorClinicInviteStaffRequestSchema,
  doctorClinicMembershipViewSchema,
  doctorOnboardRequestSchema,
  doctorProfileViewSchema,
  doctorUploadStatusResponseSchema,
  pharmacyOnboardRequestSchema,
  pharmacyOrganizationViewSchema,
  pharmacyUploadStatusResponseSchema,
  pharmacyBranchPrivateViewSchema,
  pharmacyBranchCreateRequestSchema,
  pharmacyBranchUpdateRequestSchema,
  pharmacyBranchInviteOperatorRequestSchema,
  pharmacyBranchMembershipViewSchema,
} from './index';

describe('desktop bridge contracts', () => {
  it('keeps a versioned size-bounded error envelope', () => {
    expect(BRIDGE_CONTRACT_VERSION).toBe(1);
    expect(MAX_IPC_PAYLOAD_BYTES).toBe(256 * 1024);
    const parsed = bridgeErrorSchema.parse({
      code: 'UNAUTHENTICATED',
      message: 'session required',
    });
    expect(parsed.code).toBe('UNAUTHENTICATED');
    expect(bridgeErrorSchema.parse({ code: 'FILE_CHANGED', message: 'changed' }).code).toBe(
      'FILE_CHANGED',
    );
  });

  it('keeps pharmacy domain channels off the shared Doctor/Pharmacy Auth set', () => {
    expect(ALL_CHANNELS).not.toContain(PHARMACY_CHANNELS.organizationOnboard);
    expect(PHARMACY_ALL_CHANNELS).toContain(PHARMACY_CHANNELS.evidenceUpload);
    expect(PHARMACY_ALL_CHANNELS.length).toBeGreaterThan(ALL_CHANNELS.length);
  });

  it('keeps doctor domain channels off the shared Auth set and off Pharmacy', () => {
    expect(ALL_CHANNELS).not.toContain(DOCTOR_CHANNELS.profileOnboard);
    expect(ALL_CHANNELS).not.toContain(DOCTOR_CHANNELS.evidenceUpload);
    expect(DOCTOR_ALL_CHANNELS).toContain(DOCTOR_CHANNELS.evidenceUpload);
    expect(DOCTOR_ALL_CHANNELS).not.toContain(PHARMACY_CHANNELS.organizationOnboard);
    expect(PHARMACY_ALL_CHANNELS).not.toContain(DOCTOR_CHANNELS.profileOnboard);
    expect(DOCTOR_ALL_CHANNELS.length).toBeGreaterThan(ALL_CHANNELS.length);
  });

  it('rejects legal identity and signed-target fields on safe pharmacy projections', () => {
    expect(
      pharmacyOrganizationViewSchema.safeParse({
        organizationId: '0199a5c8-0000-7000-8000-000000000001',
        publicName: 'Safe Pharmacy',
        verificationStatus: 'draft',
        status: 'draft',
        version: 1,
        createdAt: '2026-09-21T00:00:00Z',
        updatedAt: '2026-09-21T00:00:00Z',
        legalName: 'CANARY-LEGAL-PHARMACY-NAME',
        initialBranch: {
          branchId: '0199a5c8-0000-7000-8000-000000000002',
          publicName: 'Main',
          countryCode: 'EG',
          status: 'draft',
          version: 1,
        },
        membership: {
          membershipId: '0199a5c8-0000-7000-8000-000000000003',
          role: 'owner',
          status: 'pending',
        },
      }).success,
    ).toBe(false);

    expect(
      pharmacyUploadStatusResponseSchema.safeParse({
        uploadId: '0199a5c8-0000-7000-8000-000000000004',
        requirementCode: 'organization_registration_evidence',
        state: 'quarantined',
        rejectionReason: null,
        expiresAt: '2026-09-21T00:00:00Z',
        completedAt: null,
        upload_target: { url: 'https://evil.example?signature=secret' },
      }).success,
    ).toBe(false);

    expect(
      pharmacyOnboardRequestSchema.safeParse({
        legalName: 'A',
        publicName: 'B',
        legalRegistrationIdentifier: 'CR1',
        branchPublicName: 'Main',
        address: '12 Street',
        countryCode: 'US',
        latitude: 30,
        longitude: 31,
        phone: '01000000000',
      }).success,
    ).toBe(false);
  });

  it('rejects protected identifiers and signed-target fields on safe doctor projections', () => {
    expect(
      doctorProfileViewSchema.safeParse({
        doctorId: '0199a5c8-0000-7000-8000-000000000001',
        professionalDisplayName: 'Synthetic Doctor',
        specialtyId: '0199a5c8-0000-7000-8000-000000000002',
        specialtyCode: 'gp',
        specialtyLabelAr: 'طب الأسرة',
        specialtyLabelEn: 'General Practice',
        verificationStatus: 'draft',
        publicStatus: 'hidden',
        version: 1,
        approvedAt: null,
        suspendedAt: null,
        createdAt: '2026-09-21T00:00:00Z',
        updatedAt: '2026-09-21T00:00:00Z',
        nationalId: 'CANARY-NATIONAL-ID-29801011234567',
      }).success,
    ).toBe(false);

    expect(
      doctorUploadStatusResponseSchema.safeParse({
        uploadId: '0199a5c8-0000-7000-8000-000000000004',
        requirementCode: 'professional_id',
        state: 'quarantined',
        rejectionReason: null,
        expiresAt: '2026-09-21T00:00:00Z',
        completedAt: null,
        upload_target: { url: 'https://evil.example?signature=secret' },
      }).success,
    ).toBe(false);

    expect(
      doctorOnboardRequestSchema.safeParse({
        nationalId: '29801011234567',
        professionalDisplayName: 'A',
        specialtyId: 'not-a-uuid',
        syndicateNumber: null,
      }).success,
    ).toBe(false);
  });

  it('rejects unknown fields and client-owned clinic identifiers', () => {
    const location = {
      locationId: '0199a5c8-0000-7000-8000-0000000000aa',
      publicName: 'Cairo Clinic',
      countryCode: 'EG' as const,
      status: 'active' as const,
      version: 1,
      createdAt: '2026-09-21T00:00:00Z',
      updatedAt: '2026-09-21T00:00:00Z',
    };
    expect(doctorClinicLocationViewSchema.safeParse(location).success).toBe(true);
    expect(
      doctorClinicLocationViewSchema.safeParse({
        ...location,
        phone: '01000000000',
        phoneHmac: 'hmac',
        staff_count: 3,
        doctor_id: '0199a5c8-0000-7000-8000-000000000010',
      }).success,
    ).toBe(false);

    expect(
      doctorClinicLocationCreateRequestSchema.safeParse({
        publicName: 'Cairo Clinic',
        address: '1 Tahrir Square, Cairo',
        countryCode: 'EG',
        latitude: 30.0444,
        longitude: 31.2357,
        doctorId: '0199a5c8-0000-7000-8000-000000000010',
        status: 'active',
        version: 1,
      }).success,
    ).toBe(false);

    expect(
      doctorClinicLocationUpdateRequestSchema.safeParse({
        locationId: location.locationId,
        publicName: 'Renamed',
      }).success,
    ).toBe(false);

    expect(
      doctorClinicInviteStaffRequestSchema.safeParse({
        locationId: location.locationId,
        phone: '01000000000',
        role: 'secretary',
      }).success,
    ).toBe(false);

    expect(
      doctorClinicMembershipViewSchema.safeParse({
        membershipId: '0199a5c8-0000-7000-8000-0000000000bb',
        role: 'secretary',
        status: 'active',
        version: 1,
        invitedAt: '2026-09-21T00:00:00Z',
        acceptedAt: '2026-09-21T00:01:00Z',
        revokedAt: null,
        phone: '01000000000',
      }).success,
    ).toBe(false);
  });

  it('rejects mass-assigned pharmacy branch and membership fields', () => {
    const branch = {
      branchId: '0199a5c8-0000-7000-8000-0000000000aa',
      publicName: 'Cairo Pharmacy',
      countryCode: 'EG' as const,
      status: 'active' as const,
      version: 1,
      createdAt: '2026-09-21T00:00:00Z',
      updatedAt: '2026-09-21T00:00:00Z',
      address: '1 Tahrir Square, Cairo',
      latitude: 30.0444,
      longitude: 31.2357,
    };
    expect(pharmacyBranchPrivateViewSchema.safeParse(branch).success).toBe(true);
    expect(
      pharmacyBranchPrivateViewSchema.safeParse({
        ...branch,
        phone: '01000000000',
        phoneHmac: 'hmac',
        organizationId: '0199a5c8-0000-7000-8000-000000000010',
      }).success,
    ).toBe(false);

    expect(
      pharmacyBranchCreateRequestSchema.safeParse({
        publicName: 'Cairo Pharmacy',
        address: '1 Tahrir Square, Cairo',
        countryCode: 'EG',
        latitude: 30.0444,
        longitude: 31.2357,
        phone: '01000000000',
        status: 'active',
        role: 'owner',
      }).success,
    ).toBe(false);

    expect(
      pharmacyBranchUpdateRequestSchema.safeParse({
        branchId: branch.branchId,
        publicName: 'Renamed',
      }).success,
    ).toBe(false);

    expect(
      pharmacyBranchInviteOperatorRequestSchema.safeParse({
        branchId: branch.branchId,
        phone: '01000000000',
        role: 'pharmacist',
      }).success,
    ).toBe(false);

    expect(
      pharmacyBranchMembershipViewSchema.safeParse({
        membershipId: '0199a5c8-0000-7000-8000-0000000000bb',
        role: 'owner',
        status: 'active',
        version: 1,
        invitedAt: '2026-09-21T00:00:00Z',
        acceptedAt: '2026-09-21T00:01:00Z',
        revokedAt: null,
      }).success,
    ).toBe(false);

    expect(PHARMACY_ALL_CHANNELS).toContain(PHARMACY_CHANNELS.branchInviteOperator);
    expect(DOCTOR_ALL_CHANNELS).not.toContain(PHARMACY_CHANNELS.branchInviteOperator);
    expect(ALL_CHANNELS).not.toContain(PHARMACY_CHANNELS.branchesList);
  });
});
