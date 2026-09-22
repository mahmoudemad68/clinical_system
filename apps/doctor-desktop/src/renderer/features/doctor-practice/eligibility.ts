import type { AuthMe, DoctorProfileView } from '@clinic/desktop-bridge-contracts';

const PRIVILEGED_ASSURANCE = new Set(['aal2_totp', 'aal2_recovery_code']);

/**
 * Clinic-management navigation follows server-owned identity and verification
 * state. Capabilities such as clinics.location.write are not a client grant.
 */
export function canManageClinicLocations(
  me: AuthMe | undefined,
  profile: DoctorProfileView | undefined,
): boolean {
  if (!me || !profile) {
    return false;
  }
  if (me.accountType !== 'doctor' || me.status !== 'active') {
    return false;
  }
  if (!PRIVILEGED_ASSURANCE.has(me.assuranceLevel)) {
    return false;
  }
  return profile.verificationStatus === 'approved';
}
