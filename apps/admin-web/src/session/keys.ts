export const VERIFICATION_REVIEW_CAPABILITY = 'verification.case.review';

export const sessionKeys = {
  all: ['session'] as const,
  me: ['session', 'me'] as const,
  capabilities: ['session', 'capabilities'] as const,
} as const;

export const verificationKeys = {
  all: ['verification'] as const,
  queueRoot: ['verification', 'queue'] as const,
  queue: (assignment: QueueAssignmentFilter, cursor: string | null) =>
    ['verification', 'queue', assignment, cursor] as const,
  caseRoot: ['verification', 'case'] as const,
  case: (caseId: string) => ['verification', 'case', caseId] as const,
} as const;

export type QueueAssignmentFilter = 'unassigned' | 'mine' | 'all';

export type SessionStatus =
  | 'bootstrapping'
  | 'signed_out'
  | 'unauthorized'
  | 'authorized_reviewer'
  | 'session_expired';
