import type { Locale } from '@clinic/localization';
import { BranchCreate } from './BranchCreate';
import { BranchDetails } from './BranchDetails';
import { BranchList } from './BranchList';
import type { PracticeRoute } from './practiceRoute';

export function PracticeWorkspace({
  locale,
  route,
}: {
  locale: Locale;
  route: Exclude<PracticeRoute, { name: 'home' }>;
}) {
  if (route.name === 'create') {
    return <BranchCreate locale={locale} />;
  }
  if (route.name === 'detail') {
    return <BranchDetails locale={locale} branchId={route.branchId} tab={route.tab} />;
  }
  return <BranchList locale={locale} />;
}
