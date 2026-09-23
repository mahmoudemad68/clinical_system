import { describe, expect, it } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { ADMIN_ROUTE_LIST, PROHIBITED_ADMIN_PATHS, PROHIBITED_NAV_LABELS } from '@/app/routes';

function walk(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir)) {
    if (entry === 'test' || entry.endsWith('.test.ts') || entry.endsWith('.test.tsx')) {
      continue;
    }
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      out.push(...walk(full));
    } else if (full.endsWith('.ts') || full.endsWith('.tsx')) {
      out.push(full);
    }
  }
  return out;
}

describe('prohibited clinical navigation', () => {
  it('registers only verification workspace routes', () => {
    expect(ADMIN_ROUTE_LIST).toEqual(['/', '/verification', '/verification/:caseId', '/doctor-applicants/new']);
    for (const path of PROHIBITED_ADMIN_PATHS) {
      expect(ADMIN_ROUTE_LIST).not.toContain(path);
    }
  });

  it('does not add clinical labels, iframes, or HTML injection', () => {
    const files = walk(join(process.cwd(), 'src')).filter(
      (file) => !file.endsWith('/app/routes.ts') && !file.includes('/test/'),
    );
    const joined = files.map((file) => readFileSync(file, 'utf8')).join('\n');
    expect(joined).not.toContain('dangerouslySetInnerHTML');
    expect(joined).not.toMatch(/<iframe/i);
    expect(joined).not.toMatch(/\bwindow\.open\s*\(/);
    for (const label of PROHIBITED_NAV_LABELS) {
      expect(joined.includes(`>${label}<`) || joined.includes(`'${label}'`)).toBe(false);
    }
    expect(joined).not.toMatch(/to=["']\/patients/);
    expect(joined).not.toMatch(/to=["']\/prescriptions/);
    expect(joined).not.toMatch(/to=["']\/labs/);
  });
});
