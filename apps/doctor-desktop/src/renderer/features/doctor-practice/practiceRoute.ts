export type PracticeTab = 'details' | 'location' | 'staff';

export type PracticeRoute =
  | { name: 'home' }
  | { name: 'locations' }
  | { name: 'create' }
  | { name: 'detail'; locationId: string; tab: PracticeTab };

const UUID =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function parsePracticeHash(hash: string): PracticeRoute {
  const path = hash.replace(/^#/, '');
  if (path === '/practice/locations') {
    return { name: 'locations' };
  }
  if (path === '/practice/locations/new') {
    return { name: 'create' };
  }
  const match = path.match(/^\/practice\/locations\/([^/]+)(?:\/(details|location|staff))?$/);
  const locationId = match?.[1];
  if (locationId && UUID.test(locationId)) {
    const tab = match?.[2];
    return {
      name: 'detail',
      locationId,
      tab: tab === 'location' || tab === 'staff' || tab === 'details' ? tab : 'details',
    };
  }
  return { name: 'home' };
}

export function practiceHash(route: PracticeRoute): string {
  if (route.name === 'locations') {
    return '#/practice/locations';
  }
  if (route.name === 'create') {
    return '#/practice/locations/new';
  }
  if (route.name === 'detail') {
    if (route.tab === 'details') {
      return `#/practice/locations/${route.locationId}`;
    }
    return `#/practice/locations/${route.locationId}/${route.tab}`;
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

export const PHASE_03_HASH_FRAGMENTS = [
  '/practice/schedule',
  '/practice/appointment-types',
  '/practice/availability',
  '/practice/booking',
  '/practice/prices',
  '/appointments',
  '/schedule',
  '/queue',
] as const;
