import { useContext } from 'react';
import { SessionContext, type SessionValue } from '@/session/sessionContext';

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (value === null) {
    throw new Error('useSession must be used within SessionProvider');
  }

  return value;
}
