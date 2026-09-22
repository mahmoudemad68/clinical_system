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
import type { DoctorClinicLocationView } from '@clinic/desktop-bridge-contracts';
import { doctorErrorMessage, doctorStrings } from '../../strings/doctor';
import { navigatePractice } from './practiceRoute';

type LocationPage = {
  locations: DoctorClinicLocationView[];
  hasMore: boolean;
  nextCursor: string | null;
};

export function LocationList({ locale }: { locale: Locale }) {
  const t = doctorStrings[locale];
  const [extraPages, setExtraPages] = useState<LocationPage[]>([]);
  const [loadingMore, setLoadingMore] = useState(false);
  const [pageMessage, setPageMessage] = useState<string | null>(null);
  const listQuery = useQuery({
    queryKey: ['doctor', 'locations', locale],
    queryFn: async () => {
      const result = await window.clinic.doctor.listLocations({});
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

  const firstPage: LocationPage | undefined = listQuery.data
    ? {
        locations: listQuery.data.locations,
        hasMore: listQuery.data.hasMore,
        nextCursor: listQuery.data.nextCursor,
      }
    : undefined;
  const pages = firstPage ? [firstPage, ...extraPages] : extraPages;
  const locations = pages.flatMap((page) => page.locations);
  const lastPage = pages.at(-1);
  const hasMore = lastPage?.hasMore === true && lastPage.nextCursor !== null;

  async function loadMore(): Promise<void> {
    if (lastPage?.nextCursor === undefined || lastPage.nextCursor === null) {
      return;
    }
    setLoadingMore(true);
    setPageMessage(null);
    const result = await window.clinic.doctor.listLocations({ cursor: lastPage.nextCursor });
    setLoadingMore(false);
    if (!result.ok) {
      setPageMessage(doctorErrorMessage(locale, result.error.code));
      return;
    }
    setExtraPages((current) => [
      ...current,
      {
        locations: result.value.locations,
        hasMore: result.value.hasMore,
        nextCursor: result.value.nextCursor,
      },
    ]);
  }

  return (
    <Stack spacing={2} data-testid="practice-locations">
      <Typography variant="h5" component="h2">
        {t.practice.listTitle}
      </Typography>
      <Typography>{t.practice.listIntro}</Typography>
      <Stack direction="row" spacing={2}>
        <Button
          type="button"
          variant="contained"
          data-testid="add-location"
          onClick={() => navigatePractice({ name: 'create' })}
        >
          {t.practice.addLocation}
        </Button>
        <Button type="button" data-testid="refresh-locations" onClick={() => void listQuery.refetch()}>
          {t.practice.refresh}
        </Button>
      </Stack>
      {listQuery.isPending ? (
        <Typography aria-busy="true" data-testid="locations-loading">
          {t.practice.loading}
        </Typography>
      ) : null}
      {listQuery.isError ? (
        <Alert severity="error" role="alert">
          {doctorErrorMessage(locale, listQuery.error instanceof Error ? listQuery.error.message : undefined)}
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
      {listQuery.isSuccess && locations.length === 0 ? (
        <Alert severity="info" role="status" data-testid="locations-empty">
          {t.practice.empty}
        </Alert>
      ) : null}
      <Stack spacing={1} component="ul" sx={{ listStyle: 'none', p: 0, m: 0 }} data-testid="locations-list">
        {locations.map((location) => (
          <Card component="li" key={location.locationId} data-testid={`location-card-${location.locationId}`}>
            <CardActionArea
              data-testid={`open-location-${location.locationId}`}
              onClick={() =>
                navigatePractice({ name: 'detail', locationId: location.locationId, tab: 'details' })
              }
            >
              <CardContent>
                <Typography variant="h6" component="h3">
                  {location.publicName}
                </Typography>
                <Typography>
                  {t.practice.status}: {t.practice.locationStatuses[location.status]}
                </Typography>
                <Typography>
                  {t.practice.country}: {location.countryCode}
                </Typography>
                <Typography>
                  {t.practice.version}: {String(location.version)}
                </Typography>
                {location.address ? <Typography>{location.address}</Typography> : null}
              </CardContent>
            </CardActionArea>
          </Card>
        ))}
      </Stack>
      {hasMore ? (
        <Button type="button" data-testid="load-more-locations" disabled={loadingMore} onClick={() => void loadMore()}>
          {t.practice.loadMore}
        </Button>
      ) : null}
    </Stack>
  );
}
