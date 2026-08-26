#!/usr/bin/env node
/**
 * Validates packages/contracts/ai_internal JSON Schemas.
 */

import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const root = join(repoRoot, 'packages/contracts/ai_internal');
const failures = [];

const ajv = new Ajv2020({ strict: false, allErrors: true, validateFormats: true });
addFormats(ajv);

const files = readdirSync(root).filter((name) => name.endsWith('.schema.json'));
if (files.length === 0) {
    console.error('No AI internal schemas found.');
    process.exit(1);
}

const forbidden = [
    'national_id', 'password', 'token', 'secret', 'prompt', 'raw_response',
    'clinical_note', 'prescription_text', 'object_key',
];

for (const name of files) {
    const file = join(root, name);
    const schema = JSON.parse(readFileSync(file, 'utf8'));
    try {
        ajv.compile(schema);
    } catch (error) {
        failures.push(`${relative(repoRoot, file)}: ${error.message}`);
        continue;
    }
    if (schema.additionalProperties !== false) {
        failures.push(`${relative(repoRoot, file)}: additionalProperties must be false`);
    }
    if (schema['x-clinic']?.classification === 'credential') {
        failures.push(`${relative(repoRoot, file)}: credentials must not travel in this contract`);
    }
    for (const field of Object.keys(schema.properties ?? {})) {
        if (forbidden.includes(field)) {
            failures.push(`${relative(repoRoot, file)}: property "${field}" is forbidden`);
        }
    }
    const match = basename(file).match(/^(.+)\.v(\d+)\.schema\.json$/);
    if (!match) {
        failures.push(`${relative(repoRoot, file)}: filename must be <name>.v<N>.schema.json`);
    }
}

if (failures.length > 0) {
    console.error(`AI internal contract validation failed:\n${failures.map((f) => `  ${f}`).join('\n')}`);
    process.exit(1);
}

console.log(`AI internal contracts valid: ${files.length} schema(s) checked.`);
