import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  assertDeviceGatewaySourcesCookieless,
  cookieNamesOnly,
  requestHasSessionCookies,
} from './run-cookieless-electron-transport.mjs';

describe('cookieless Electron transport helpers', () => {
  it('accepts the cookieless device helper and rejects CSRF cookie copies', () => {
    assertDeviceGatewaySourcesCookieless(`
      export const DEVICE_NET_FETCH_CREDENTIALS = 'omit' as const;
      return net.fetch(url, { method, headers, credentials: DEVICE_NET_FETCH_CREDENTIALS });
    `);
    assert.throws(() =>
      assertDeviceGatewaySourcesCookieless(`
        return net.fetch(url, { method, headers });
      `),
    );
    assert.throws(() =>
      assertDeviceGatewaySourcesCookieless(`
        export const DEVICE_NET_FETCH_CREDENTIALS = 'omit' as const;
        return net.fetch(url, { credentials: 'include' });
      `),
    );
    assert.throws(() =>
      assertDeviceGatewaySourcesCookieless(`
        export const DEVICE_NET_FETCH_CREDENTIALS = 'omit' as const;
        const token = 'XSRF-TOKEN';
        return net.fetch(url, { credentials: DEVICE_NET_FETCH_CREDENTIALS });
      `),
    );
  });

  it('reports only safe cookie names and detects session cookies without values', () => {
    assert.deepEqual(cookieNamesOnly(['clinic_session', 'other', 'XSRF-TOKEN']), [
      'clinic_session',
      'XSRF-TOKEN',
    ]);
    assert.equal(requestHasSessionCookies('clinic_session=redacted; Path=/'), true);
    assert.equal(requestHasSessionCookies('theme=light'), false);
  });
});
