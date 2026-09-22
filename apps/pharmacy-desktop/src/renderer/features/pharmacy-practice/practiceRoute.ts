export type PracticeTab = 'details' | 'location' | 'team';

export type PracticeRoute =
  | { name: 'home' }
  | { name: 'branches' }
  | { name: 'create' }
  | { name: 'detail'; branchId: string; tab: PracticeTab };

const UUID =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function parsePracticeHash(hash: string): PracticeRoute {
  const path = hash.replace(/^#/, '');
  if (path === '/practice/branches') {
    return { name: 'branches' };
  }
  if (path === '/practice/branches/new') {
    return { name: 'create' };
  }
  const match = path.match(/^\/practice\/branches\/([^/]+)(?:\/(details|location|team))?$/);
  const branchId = match?.[1];
  if (branchId && UUID.test(branchId)) {
    const tab = match?.[2];
    return {
      name: 'detail',
      branchId,
      tab: tab === 'location' || tab === 'team' || tab === 'details' ? tab : 'details',
    };
  }
  return { name: 'home' };
}

export function practiceHash(route: PracticeRoute): string {
  if (route.name === 'branches') {
    return '#/practice/branches';
  }
  if (route.name === 'create') {
    return '#/practice/branches/new';
  }
  if (route.name === 'detail') {
    if (route.tab === 'details') {
      return `#/practice/branches/${route.branchId}`;
    }
    return `#/practice/branches/${route.branchId}/${route.tab}`;
  }
  return '#/';
}

export function navigatePractice(route: PracticeRoute): void {
  const next = practiceHash(route).replace(/^#/, '');
  const current = window.location.hash.replace(/^#/, '');
  if (current !== next) {
    window.location.hash = next;
  }
  window.dispatchEvent(new HashChangeEvent('hashchange'));
}

export const PHASE_10_HASH_FRAGMENTS = [
  '/inventory',
  '/pos',
  '/purchasing',
  '/catalog',
  '/alerts',
  '/sales',
  '/ai',
  '/home',
  '/practice/inventory',
  '/practice/pos',
  '/practice/purchasing',
  '/practice/catalog',
  '/native',
  '/integrated',
] as const;
