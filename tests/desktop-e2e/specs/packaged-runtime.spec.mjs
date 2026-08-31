import { expect, browser, $ } from '@wdio/globals';

const ORIGIN = process.env.CLINIC_DESKTOP_ORIGIN;
const PRODUCT = process.env.CLINIC_DESKTOP_PRODUCT;

function assertCustomOrigin(url) {
  expect(url.startsWith(ORIGIN)).toBe(true);
  expect(url.startsWith('file:')).toBe(false);
  expect(url.startsWith('http:')).toBe(false);
  expect(url.startsWith('https:')).toBe(false);
}

describe(`Packaged ${PRODUCT} runtime`, () => {
  it('boots on the privileged custom origin and not file://', async () => {
    const page = await browser.execute(() => ({
      href: location.href,
      origin: location.origin,
      protocol: location.protocol,
      title: document.title,
      ready: document.readyState,
      rootLength: document.getElementById('root')?.innerHTML.length ?? -1,
      scripts: [...document.scripts].map((script) => script.src),
      resources: performance.getEntriesByType('resource').map((entry) => ({
        name: entry.name,
        type: entry.initiatorType,
        size: 'transferSize' in entry ? entry.transferSize : null,
      })),
    }));
    assertCustomOrigin(page.href);
    expect(page.origin).toBe(ORIGIN);
    expect(page.protocol).toBe(`${ORIGIN.split('://')[0]}:`);
    if (page.rootLength <= 0) {
      throw new Error(`Packaged renderer did not mount React. ${JSON.stringify(page)}`);
    }

    await $('[data-testid="product-title"]').waitForDisplayed();
    const url = await browser.getUrl();
    assertCustomOrigin(url);
  });

  it('keeps Node out of the renderer and exposes only the typed clinic bridge', async () => {
    const isolation = await browser.execute(() => ({
      requireType: typeof window.require,
      processType: typeof window.process,
      electronType: typeof window.electron,
      clinicType: typeof window.clinic,
      clinicFrozen: Object.isFrozen(window.clinic),
    }));

    expect(isolation.requireType).toBe('undefined');
    expect(isolation.processType).toBe('undefined');
    expect(isolation.electronType).toBe('undefined');
    expect(isolation.clinicType).toBe('object');
  });

  it('shows the unsigned-in boot shell and does not persist session material in renderer storage', async () => {
    await $('[data-testid="product-title"]').waitForDisplayed();
    await $('[data-testid="health-heading"]').waitForDisplayed();

    await browser.waitUntil(async () => {
      const login = await $('[data-testid="login-form"]').isExisting();
      const unavailable = await $('[data-testid="keystore-unavailable"]').isExisting();
      return login || unavailable;
    }, {
      timeout: 15_000,
      timeoutMsg: 'packaged boot showed neither login nor an honest keystore-unavailable state',
    });

    const loginShown = await $('[data-testid="login-form"]').isExisting();
    if (loginShown) {
      await $('[data-testid="sign-in"]').waitForDisplayed();
    }

    await expect($('[data-testid="session-panel"]')).not.toBeDisplayed();

    const title = await $('[data-testid="product-title"]').getText();
    expect(title).toBe(PRODUCT);

    const storage = await browser.execute(() => ({
      localStorageKeys: Object.keys(localStorage),
      sessionStorageKeys: Object.keys(sessionStorage),
      cookie: document.cookie,
    }));

    expect(storage.localStorageKeys).toEqual([]);
    expect(storage.sessionStorageKeys).toEqual([]);
    expect(storage.cookie).not.toMatch(/access_token|refresh_token|session/i);
  });

  it('refuses hostile navigation and new windows', async () => {
    const before = await browser.getUrl();
    assertCustomOrigin(before);

    await browser.execute(() => {
      location.href = 'https://example.com/';
    });

    await browser.waitUntil(async () => {
      const url = await browser.getUrl();
      return url.startsWith(ORIGIN) && !url.startsWith('https:');
    }, {
      timeout: 8_000,
      timeoutMsg: 'packaged window navigated away from the custom origin',
    });

    const opened = await browser.execute(() => {
      const child = window.open('https://example.com/');
      return child === null;
    });
    expect(opened).toBe(true);
    assertCustomOrigin(await browser.getUrl());
  });

  it('switches English to Arabic and sets RTL', async () => {
    const select = $('[data-testid="language-select"]');
    await select.waitForDisplayed();
    await select.selectByAttribute('value', 'ar');

    await browser.waitUntil(async () => {
      const dir = await browser.execute(() => document.documentElement.getAttribute('dir'));
      return dir === 'rtl';
    }, {
      timeout: 8_000,
      timeoutMsg: 'document dir did not become rtl after Arabic locale selection',
    });

    const lang = await browser.execute(() => document.documentElement.getAttribute('lang'));
    expect(lang).toBe('ar');
    assertCustomOrigin(await browser.getUrl());
  });
});
