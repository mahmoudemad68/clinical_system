import type { components } from '@clinic/api-client/schema';

type QueueItem =
  | components['schemas']['AdminVerificationQueueItem']
  | components['schemas']['AdminPharmacyVerificationQueueItem'];

type ReviewCase =
  | components['schemas']['AdminVerificationCase']
  | components['schemas']['AdminPharmacyVerificationCase'];

export function isDoctorVerificationQueueItem(
  item: QueueItem,
): item is components['schemas']['AdminVerificationQueueItem'] {
  return item.case_type === 'doctor_verification';
}

export function isDoctorVerificationCase(
  item: ReviewCase,
): item is components['schemas']['AdminVerificationCase'] {
  return item.case_type === 'doctor_verification';
}
