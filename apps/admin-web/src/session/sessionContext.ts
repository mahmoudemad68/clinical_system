import { createContext } from 'react';
import type { components } from '@clinic/api-client/schema';
import type { SessionStatus } from '@/session/keys';

type MeResult = components['schemas']['MeResult'];

export interface SessionValue {
  status: SessionStatus;
  me: MeResult | null;
  capabilities: readonly string[];
  canReviewVerification: boolean;
  canCreateDoctor: boolean;
  refresh: () => Promise<void>;
  logout: () => Promise<void>;
  expireSession: () => void;
}

export const SessionContext = createContext<SessionValue | null>(null);
