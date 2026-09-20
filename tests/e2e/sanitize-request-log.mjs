/**
 * E2E request logging must never emit query strings. Reviewer HMAC
 * signatures, X-Amz-* parameters, signed pagination cursors, and tokens
 * live in search. Pathname + query presence is the only safe access-log shape.
 */

const SENSITIVE_ASSIGNMENT = new RegExp(
  String.raw`(?:^|[\s&;])((?:X-Amz-[\w-]+|signature|token|cursor)=)[^\s&;]*`,
  'gi',
);

export function sanitizedRequestLogPath(rawUrl) {
  const raw = typeof rawUrl === 'string' && rawUrl !== '' ? rawUrl : '/';
  try {
    const parsed = new URL(raw, 'http://admin-web.invalid');
    const pathname = parsed.pathname === '' ? '/' : parsed.pathname;
    const queryPresent = parsed.search !== '' && parsed.search !== '?';

    return { pathname, queryPresent };
  } catch {
    return { pathname: '/unparseable', queryPresent: false };
  }
}

export function formatSanitizedAccessLog({
  method,
  rawUrl,
  cookie,
  cookieNames,
  auth,
  xsrf,
  setCookie,
  status,
}) {
  const { pathname, queryPresent } = sanitizedRequestLogPath(rawUrl);

  return `${method} ${pathname} qs=${queryPresent ? '1' : '0'} cookie=${cookie} names=${cookieNames} auth=${auth} xsrf=${xsrf} set-cookie=${setCookie} -> ${status}\n`;
}

/**
 * Strip query strings and fragments from an already-rendered log line
 * (php -S may include REQUEST_URI; PHP warnings may interpolate it).
 */
export function stripRequestQueryFromLogLine(line) {
  if (typeof line !== 'string' || line === '') {
    return line;
  }

  let next = line.replace(/#[^\s"'\\]*/g, '');
  next = next.replace(/\?[^\s"'\\]*/g, (match) => (match.length > 1 ? ' qs=1' : ''));

  return next.replace(SENSITIVE_ASSIGNMENT, '$1[redacted]');
}
