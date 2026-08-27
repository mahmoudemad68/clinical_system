import { describe, expect, it } from 'vitest';
import { direction, resolveLocale } from './index';

describe('locale negotiation', () => {
  it('resolves egyptian arabic to arabic rtl', () => {
    expect(resolveLocale('ar-EG')).toBe('ar');
    expect(direction('ar')).toBe('rtl');
  });

  it('falls back silently for an unsupported tag', () => {
    expect(resolveLocale('zz')).toBe('en');
  });
});
