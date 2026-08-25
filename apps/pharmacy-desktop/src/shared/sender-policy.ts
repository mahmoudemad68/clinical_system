/**
 * Origin policy for IPC senders.
 *
 * Extracted as a pure function so it can be tested by *behaviour* rather than
 * by grepping the main process for a substring. A substring assertion cannot
 * tell the difference between a correct check and a plausible-looking one; this
 * can be handed a hostile URL and asked what it decides.
 *
 * The WebContents-identity and top-level-frame checks stay in the main process
 * because they need live Electron objects. This covers the origin half.
 */
export function isTrustedFrameOrigin(
  frameUrl: string,
  packagedOrigin: string,
  isPackaged: boolean,
): boolean {
  let url: URL;

  try {
    url = new URL(frameUrl);
  } catch {
    return false;
  }

  // Reject any URL carrying credentials before comparing anything.
  //
  // `scheme://user:pass@-/` parses with host `-`, so a protocol+host
  // comparison alone accepts it — the behavioural test for this caught exactly
  // that. Embedded credentials are a long-standing origin-confusion vector and
  // no legitimate frame URL in this application has them.
  if (url.username !== '' || url.password !== '') {
    return false;
  }

  // Exact origin, not merely the scheme. `scheme:` alone would admit
  // `scheme://anything`, which is not the window we serve.
  if (`${url.protocol}//${url.host}` === packagedOrigin) {
    return true;
  }

  // The development server exists only in an unpackaged build. Gating on
  // isPackaged means the branch cannot exist in a shipped artifact, rather than
  // relying on a comment claiming it is unreachable.
  if (!isPackaged) {
    return (
      (url.protocol === 'http:' || url.protocol === 'https:') &&
      (url.hostname === 'localhost' || url.hostname === '127.0.0.1')
    );
  }

  return false;
}
