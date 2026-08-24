#!/usr/bin/env node
/**
 * Validates the event contracts in packages/contracts/events.
 *
 * Required by Phase 00 §3.4. Enforces the rules stated in
 * packages/contracts/events/README.md that a plain JSON Schema check cannot:
 * filename/identity agreement, closed payloads, classification limits, and the
 * presence of ownership metadata.
 *
 * Exit code 0 when every schema passes, 1 otherwise.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const eventsRoot = join(repoRoot, 'packages/contracts/events');

const ALLOWED_CLASSIFICATIONS = ['public', 'internal', 'personal', 'sensitive'];
const REQUIRED_X_CLINIC = ['owning_module', 'classification', 'consumers', 'retention_days'];

/** @type {{file: string, message: string}[]} */
const failures = [];
let checked = 0;

const fail = (file, message) => failures.push({ file: relative(repoRoot, file), message });

/** Recursively collect *.schema.json files. */
function collect(dir) {
    const out = [];
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) out.push(...collect(full));
        else if (entry.endsWith('.schema.json')) out.push(full);
    }
    return out;
}

const ajv = new Ajv2020({ strict: false, allErrors: true, validateFormats: true });
addFormats(ajv);

const files = collect(eventsRoot);
if (files.length === 0) {
    console.error('No event schemas found under packages/contracts/events.');
    process.exit(1);
}

// The envelope is validated first and separately: every payload schema is
// meaningless if the envelope itself is malformed.
const envelopePath = join(eventsRoot, 'envelope.schema.json');
let envelope;
try {
    envelope = JSON.parse(readFileSync(envelopePath, 'utf8'));
    ajv.compile(envelope);
} catch (error) {
    console.error(`envelope.schema.json is not a valid schema: ${error.message}`);
    process.exit(1);
}

for (const file of files) {
    checked += 1;
    const name = basename(file);
    if (file === envelopePath) continue;

    let schema;
    try {
        schema = JSON.parse(readFileSync(file, 'utf8'));
    } catch (error) {
        fail(file, `not valid JSON: ${error.message}`);
        continue;
    }

    // 1. It must be a compilable JSON Schema 2020-12 document.
    try {
        ajv.compile(schema);
    } catch (error) {
        fail(file, `not a valid JSON Schema: ${error.message}`);
        continue;
    }

    // 2. Filename must agree with the identity inside the file, so a schema
    //    cannot be silently mis-filed under the wrong event or version.
    const match = name.match(/^(.+)\.v(\d+)\.schema\.json$/);
    if (!match) {
        fail(file, 'filename must be <event_name>.v<N>.schema.json');
        continue;
    }
    const [, fileEventName, fileVersion] = match;
    const moduleDir = basename(join(file, '..'));
    const expectedTitlePrefix = `${moduleDir}.${fileEventName}`;

    if (typeof schema.title !== 'string' || !schema.title.startsWith(expectedTitlePrefix)) {
        fail(file, `title should start with "${expectedTitlePrefix}" but is "${schema.title}"`);
    }
    if (!schema.title?.endsWith(`v${fileVersion}`)) {
        fail(file, `title should end with "v${fileVersion}" to match the filename`);
    }
    if (typeof schema.$id !== 'string' || !schema.$id.endsWith(name)) {
        fail(file, `$id should end with the filename "${name}"`);
    }

    // 3. Payloads are closed. An open payload lets a producer leak a field no
    //    consumer or reviewer ever saw.
    if (schema.additionalProperties !== false) {
        fail(file, 'payload schema must set "additionalProperties": false');
    }

    // 4. Ownership and retention metadata must be present and sane.
    const x = schema['x-clinic'];
    if (!x || typeof x !== 'object') {
        fail(file, 'missing the "x-clinic" metadata block');
    } else {
        for (const key of REQUIRED_X_CLINIC) {
            if (!(key in x)) fail(file, `"x-clinic" is missing "${key}"`);
        }
        if (x.classification === 'credential') {
            fail(file, 'classification "credential" is forbidden: credentials never travel in an event');
        }
        if (x.classification && !ALLOWED_CLASSIFICATIONS.includes(x.classification)) {
            fail(file, `classification "${x.classification}" is not one of ${ALLOWED_CLASSIFICATIONS.join(', ')}`);
        }
        if (x.consumers && !Array.isArray(x.consumers)) {
            fail(file, '"x-clinic.consumers" must be an array');
        }
        if (x.retention_days !== undefined && !Number.isInteger(x.retention_days)) {
            fail(file, '"x-clinic.retention_days" must be an integer');
        }
    }

    // 5. Cheap guard against obviously sensitive field names travelling in a
    //    payload. This is a tripwire, not a substitute for review.
    const forbiddenFieldNames = [
        'national_id', 'nationalId', 'password', 'token', 'secret', 'api_key',
        'apiKey', 'otp', 'clinical_note', 'prescription_text', 'lab_result_text',
        'object_key', 'prompt', 'raw_response',
    ];
    const declared = Object.keys(schema.properties ?? {});
    for (const field of declared) {
        if (forbiddenFieldNames.includes(field)) {
            fail(file, `property "${field}" must not appear in an event payload`);
        }
    }
}

if (failures.length > 0) {
    console.error(`\nEvent contract validation failed (${failures.length} problem(s)):\n`);
    for (const { file, message } of failures) console.error(`  ${file}\n    ${message}`);
    console.error('');
    process.exit(1);
}

console.log(`Event contracts valid: ${checked} schema(s) checked.`);
