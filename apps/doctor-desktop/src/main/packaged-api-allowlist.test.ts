import { describe, expect, it } from 'vitest';
import { PACKAGED_API_ALLOWED_ORIGINS } from './packaged-api-allowlist';

describe('Clinic Doctor — baked packaged API allowlist', () => {
  it('is empty in unit tests so a missing bake fails closed', () => {
    expect([...PACKAGED_API_ALLOWED_ORIGINS]).toEqual([]);
  });
});
