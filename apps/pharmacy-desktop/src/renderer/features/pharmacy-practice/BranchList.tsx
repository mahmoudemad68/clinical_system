import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardActionArea from '@mui/material/CardActionArea';
import CardContent from '@mui/material/CardContent';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import type { Locale } from '@clinic/localization';
import type { PharmacyBranchPrivateView } from '@clinic/desktop-bridge-contracts';
import { pharmacyErrorMessage, pharmacyStrings } from '../../strings';
import { navigatePractice } from './practiceRoute';

type BranchPage = {
  branches: PharmacyBranchPrivateView[];
  hasMore: boolean;
  nextCursor: string | null;
};

export function BranchList({ locale }: { locale: Locale }) {
  const t = pharmacyStrings[locale];
  const [extraPages, setExtraPages] = useState<BranchPage[]>([]);
  const [loadingMore, setLoadingMore] = useState(false);
  const [pageMessage, setPageMessage] = useState<string | null>(null);
  const listQuery = useQuery({
    queryKey: ['pharmacy', 'branches', locale],
    queryFn: async () => {
      const result = await window.clinic.pharmacy.listBranches({});
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value;
    },
  });

  useEffect(() => {
    setExtraPages([]);
    setPageMessage(null);
  }, [listQuery.dataUpdatedAt]);

  const firstPage: BranchPage | undefined = listQuery.data
    ? {
        branches: listQuery.data.branches,
        hasMore: listQuery.data.hasMore,
        nextCursor: listQuery.data.nextCursor,
      }
    : undefined;
  const pages = firstPage ? [firstPage, ...extraPages] : extraPages;
  const branches = pages.flatMap((page) => page.branches);
  const lastPage = pages.at(-1);
  const hasMore = lastPage?.hasMore === true && lastPage.nextCursor !== null;

  async function loadMore(): Promise<void> {
    if (lastPage?.nextCursor === undefined || lastPage.nextCursor === null) {
      return;
    }
    setLoadingMore(true);
    setPageMessage(null);
    const result = await window.clinic.pharmacy.listBranches({ cursor: lastPage.nextCursor });
    setLoadingMore(false);
    if (!result.ok) {
      setPageMessage(pharmacyErrorMessage(locale, result.error.code));
      return;
    }
    setExtraPages((current) => [
      ...current,
      {
        branches: result.value.branches,
        hasMore: result.value.hasMore,
        nextCursor: result.value.nextCursor,
      },
    ]);
  }

  return (
    <Stack spacing={2} data-testid="practice-branches">
      <Typography variant="h5" component="h2">
        {t.practice.listTitle}
      </Typography>
      <Typography>{t.practice.listIntro}</Typography>
      <Stack direction="row" spacing={2} sx={{ flexWrap: 'wrap' }}>
        <Button
          type="button"
          variant="contained"
          data-testid="add-branch"
          onClick={() => navigatePractice({ name: 'create' })}
        >
          {t.practice.addBranch}
        </Button>
        <Button type="button" data-testid="refresh-branches" onClick={() => void listQuery.refetch()}>
          {t.practice.refresh}
        </Button>
      </Stack>
      {listQuery.isPending ? (
        <Typography aria-busy="true" data-testid="branches-loading">
          {t.practice.loading}
        </Typography>
      ) : null}
      {listQuery.isError ? (
        <Alert severity="error" role="alert">
          {pharmacyErrorMessage(locale, listQuery.error instanceof Error ? listQuery.error.message : undefined)}
          <Button type="button" onClick={() => void listQuery.refetch()}>
            {t.practice.retry}
          </Button>
        </Alert>
      ) : null}
      {pageMessage ? (
        <Alert severity="error" role="alert">
          {pageMessage}
        </Alert>
      ) : null}
      {listQuery.isSuccess && branches.length === 0 ? (
        <Alert severity="info" role="status" data-testid="branches-empty">
          {t.practice.empty}
        </Alert>
      ) : null}
      <Stack spacing={1} component="ul" sx={{ listStyle: 'none', p: 0, m: 0 }} data-testid="branches-list">
        {branches.map((branch) => (
          <Card component="li" key={branch.branchId} data-testid={`branch-card-${branch.branchId}`}>
            <CardActionArea
              data-testid={`open-branch-${branch.branchId}`}
              onClick={() =>
                navigatePractice({ name: 'detail', branchId: branch.branchId, tab: 'details' })
              }
            >
              <CardContent>
                <Typography variant="h6" component="h3">
                  {branch.publicName}
                </Typography>
                <Typography>
                  {t.practice.status}: {t.practice.branchStatuses[branch.status]}
                </Typography>
                <Typography>
                  {t.practice.country}: {branch.countryCode}
                </Typography>
                <Typography>
                  {t.practice.version}: {String(branch.version)}
                </Typography>
              </CardContent>
            </CardActionArea>
          </Card>
        ))}
      </Stack>
      {hasMore ? (
        <Button type="button" data-testid="load-more-branches" disabled={loadingMore} onClick={() => void loadMore()}>
          {t.practice.loadMore}
        </Button>
      ) : null}
    </Stack>
  );
}
