import { createTheme } from '@mui/material/styles';
import { palette, radius, typography } from '@clinic/design-tokens';

export function createDoctorTheme(direction: 'ltr' | 'rtl') {
  return createTheme({
    direction,
    palette: {
      primary: { main: palette.seed },
      success: { main: palette.operational },
      warning: { main: palette.degraded },
      error: { main: palette.unavailable },
    },
    typography: {
      fontFamily: typography.fontFamily,
    },
    shape: { borderRadius: radius.md },
    components: {
      MuiButton: {
        styleOverrides: {
          root: {
            minHeight: 40,
            textTransform: 'none',
          },
        },
      },
    },
  });
}
