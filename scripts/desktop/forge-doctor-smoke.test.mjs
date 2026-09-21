import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  assertSmokeSnapshot,
  isCspEvalViolation,
  pickRendererTarget,
} from './run-forge-doctor-smoke.mjs';

describe('Forge Doctor smoke helpers', () => {
  it('detects CSP eval violations and ignores unrelated console noise', () => {
    assert.equal(isCspEvalViolation("Refused to evaluate a string as JavaScript because 'unsafe-eval' is not an allowed source"), true);
    assert.equal(isCspEvalViolation('EvalError: call to eval() blocked by CSP'), true);
    assert.equal(isCspEvalViolation('Clinic Doctor mounted'), false);
  });

  it('selects only a loopback renderer page from CDP targets', () => {
    const page = pickRendererTarget([
      { type: 'page', url: 'https://example.com/', webSocketDebuggerUrl: 'ws://127.0.0.1:1' },
      { type: 'page', url: 'http://localhost:3000/main_window', webSocketDebuggerUrl: 'ws://127.0.0.1:2' },
    ]);
    assert.equal(page.webSocketDebuggerUrl, 'ws://127.0.0.1:2');
    assert.equal(pickRendererTarget([{ type: 'browser', url: 'http://localhost:3000/' }]), null);
  });

  it('accepts a mounted Doctor development snapshot and rejects packaged-origin or Node leaks', () => {
    assertSmokeSnapshot({
      href: 'http://localhost:3000/main_window',
      title: 'Clinic Doctor',
      product: 'Clinic Doctor',
      rootLength: 12,
      login: true,
      keystore: false,
      clinicType: 'object',
      doctorType: 'object',
      pharmacyType: 'undefined',
      requireType: 'undefined',
      processType: 'undefined',
      hasInvoke: false,
    });

    assert.throws(() =>
      assertSmokeSnapshot({
        href: 'clinic-doctor-app://-/index.html',
        title: 'Clinic Doctor',
        product: 'Clinic Doctor',
        rootLength: 12,
        login: true,
        clinicType: 'object',
        doctorType: 'object',
        pharmacyType: 'undefined',
        requireType: 'undefined',
        processType: 'undefined',
        hasInvoke: false,
      }),
    );
  });
});
