export const ENGINEERING_DEFAULT_DECISIONS = [
  'approved',
  'rejected',
  'changes_requested',
] as const;

export type EngineeringDefaultDecision = (typeof ENGINEERING_DEFAULT_DECISIONS)[number];

export const ENGINEERING_DEFAULT_REASON_PAIRS: Record<
  EngineeringDefaultDecision,
  readonly string[]
> = {
  approved: ['approved'],
  rejected: ['evidence_incomplete', 'identity_mismatch'],
  changes_requested: ['evidence_incomplete', 'documents_illegible'],
};

export function reasonsForDecision(decision: EngineeringDefaultDecision): readonly string[] {
  return ENGINEERING_DEFAULT_REASON_PAIRS[decision];
}

export function isAllowedDecisionPair(decision: string, reasonCode: string): boolean {
  if (
    decision !== 'approved' &&
    decision !== 'rejected' &&
    decision !== 'changes_requested'
  ) {
    return false;
  }

  return ENGINEERING_DEFAULT_REASON_PAIRS[decision].includes(reasonCode);
}
