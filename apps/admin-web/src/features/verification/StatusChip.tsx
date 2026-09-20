import Chip from '@mui/material/Chip';
import { useTranslation } from 'react-i18next';

interface StatusChipProps {
  value: string;
  prefix?: 'status' | 'decision';
}

export function StatusChip({ value, prefix = 'status' }: StatusChipProps) {
  const { t } = useTranslation();
  const label = t(`${prefix}.${value}`, { defaultValue: value });
  const color =
    value === 'approved' || value === 'available' || value === 'clean'
      ? 'success'
      : value === 'rejected' || value === 'suspended'
        ? 'error'
        : value === 'changes_requested' || value === 'other'
          ? 'warning'
          : 'default';

  return <Chip size="small" label={label} color={color} variant="outlined" />;
}
