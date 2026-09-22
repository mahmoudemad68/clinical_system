import type { AuthMe, PharmacyOrganizationView } from '@clinic/desktop-bridge-contracts';

const PRIVILEGED_ASSURANCE = new Set(['aal2_totp', 'aal2_recovery_code']);

/**
 * Branch-management navigation follows server-owned identity and organization
 * state. A capability name on /me is not a client grant.
 */
export function canManagePharmacyBranches(
  me: AuthMe | undefined,
  organization: PharmacyOrganizationView | undefined,
): boolean {
  if (!me || !organization) {
    return false;
  }
  if (me.accountType !== 'pharmacy' || me.status !== 'active') {
    return false;
  }
  if (!PRIVILEGED_ASSURANCE.has(me.assuranceLevel)) {
    return false;
  }
  if (organization.membership.role !== 'owner' || organization.membership.status !== 'active') {
    return false;
  }
  return organization.verificationStatus === 'approved' && organization.status === 'active';
}
