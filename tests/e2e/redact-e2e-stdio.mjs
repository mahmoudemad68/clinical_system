#!/usr/bin/env node
/**
 * Line-buffer stdin → stdout, stripping query strings and leftover
 * signature/X-Amz/token/cursor assignments. Used in CI so php -S
 * access/warning lines cannot reprint signed reviewer URLs when
 * /tmp/clinic-e2e-laravel.log is tailed into GitHub Actions.
 */
import { writeSync } from 'node:fs';
import { stripRequestQueryFromLogLine } from './sanitize-request-log.mjs';

function emit(line) {
  writeSync(1, `${stripRequestQueryFromLogLine(line)}\n`);
}

let buffer = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => {
  buffer += chunk;
  let newline = buffer.indexOf('\n');
  while (newline !== -1) {
    emit(buffer.slice(0, newline));
    buffer = buffer.slice(newline + 1);
    newline = buffer.indexOf('\n');
  }
});
process.stdin.on('end', () => {
  if (buffer !== '') {
    emit(buffer);
  }
});
