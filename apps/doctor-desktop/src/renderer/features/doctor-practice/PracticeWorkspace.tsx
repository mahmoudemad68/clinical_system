import type { Locale } from '@clinic/localization';
import { LocationCreate } from './LocationCreate';
import { LocationDetails } from './LocationDetails';
import { LocationList } from './LocationList';
import type { PracticeRoute } from './practiceRoute';

export function PracticeWorkspace({
  locale,
  route,
}: {
  locale: Locale;
  route: Exclude<PracticeRoute, { name: 'home' }>;
}) {
  if (route.name === 'create') {
    return <LocationCreate locale={locale} />;
  }
  if (route.name === 'detail') {
    return <LocationDetails locale={locale} locationId={route.locationId} tab={route.tab} />;
  }
  return <LocationList locale={locale} />;
}
