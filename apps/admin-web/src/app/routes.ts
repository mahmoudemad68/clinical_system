/**
 * Admin verification routes only. Clinical navigation must never appear.
 */
export const ADMIN_ROUTE_PATHS = {
  home: '/',
  verificationQueue: '/verification',
  verificationCase: '/verification/:caseId',
} as const;

export const ADMIN_ROUTE_LIST = [
  ADMIN_ROUTE_PATHS.home,
  ADMIN_ROUTE_PATHS.verificationQueue,
  ADMIN_ROUTE_PATHS.verificationCase,
] as const;

export const PROHIBITED_ADMIN_PATHS = [
  '/patients',
  '/patient',
  '/prescriptions',
  '/labs',
  '/encounters',
  '/medical-records',
  '/appointments',
  '/clinical',
  '/diagnosis',
] as const;

export const PROHIBITED_NAV_LABELS = [
  'Patients',
  'Prescriptions',
  'Labs',
  'Encounters',
  'Medical records',
  'Appointments',
  'Diagnosis',
] as const;
