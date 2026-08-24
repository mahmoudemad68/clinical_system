#!/usr/bin/env node
/**
 * Detects breaking HTTP contract changes against a baseline revision.
 *
 * Required by Phase 00 §3.3. Encodes exactly the compatibility rules ADR 0003
 * states, so the gate cannot drift from the decision it enforces.
 *
 * Usage:
 *   node scripts/contracts/check-breaking.mjs [baseRef]
 *
 * baseRef defaults to origin/main, then main. When no baseline exists (a fresh
 * repository, or the contract is new in this branch) the check reports that and
 * exits 0: there is nothing to break yet.
 *
 * CI additionally runs the upstream tufin/oasdiff image as an independent
 * second opinion. This script is the rule-of-record; oasdiff is the cross-check.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { parse } from 'yaml';

const SPEC_PATH = 'packages/contracts/openapi/openapi.yaml';
const repoRoot = fileURLToPath(new URL('../..', import.meta.url));

const git = (args) =>
    execFileSync('git', args, { cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });

function resolveBaseRef(explicit) {
    if (explicit) return explicit;
    for (const candidate of ['origin/main', 'main']) {
        try {
            git(['rev-parse', '--verify', '--quiet', `${candidate}^{commit}`]);
            return candidate;
        } catch { /* try the next candidate */ }
    }
    return null;
}

function loadBaseline(baseRef) {
    try {
        return parse(git(['show', `${baseRef}:${SPEC_PATH}`]));
    } catch {
        return null;
    }
}

/** Walk a schema-ish object, yielding [pointer, node] for every object node. */
function* walk(node, pointer = '') {
    if (node === null || typeof node !== 'object') return;
    yield [pointer, node];
    for (const [key, value] of Object.entries(node)) {
        const escaped = String(key).replace(/~/g, '~0').replace(/\//g, '~1');
        yield* walk(value, `${pointer}/${escaped}`);
    }
}

const breaking = [];
const report = (kind, where, detail) => breaking.push({ kind, where, detail });

const baseRef = resolveBaseRef(process.argv[2]);
if (!baseRef) {
    console.log('No baseline ref (origin/main or main) exists yet. Nothing to compare; contract is new.');
    process.exit(0);
}

const baseline = loadBaseline(baseRef);
if (!baseline) {
    console.log(`${SPEC_PATH} does not exist at ${baseRef}. Contract is new on this branch; nothing to break.`);
    process.exit(0);
}

const current = parse(readFileSync(new URL(`../../${SPEC_PATH}`, import.meta.url), 'utf8'));

// ---------------------------------------------------------------- operations

const opsOf = (doc) => {
    const ops = new Map();
    for (const [path, item] of Object.entries(doc.paths ?? {})) {
        for (const [method, op] of Object.entries(item ?? {})) {
            if (!['get', 'put', 'post', 'delete', 'patch', 'head', 'options', 'trace'].includes(method)) continue;
            ops.set(`${method.toUpperCase()} ${path}`, op);
        }
    }
    return ops;
};

const baseOps = opsOf(baseline);
const curOps = opsOf(current);

for (const [key, baseOp] of baseOps) {
    const curOp = curOps.get(key);
    if (!curOp) {
        report('operation-removed', key, 'operation no longer exists');
        continue;
    }

    if (baseOp.operationId && curOp.operationId !== baseOp.operationId) {
        report('operationId-changed', key, `${baseOp.operationId} -> ${curOp.operationId}`);
    }

    // A parameter that was optional cannot become required, and a parameter
    // cannot disappear from under a client that still sends it.
    const baseParams = new Map((baseOp.parameters ?? []).map((p) => [`${p.in}:${p.name}`, p]));
    const curParams = new Map((curOp.parameters ?? []).map((p) => [`${p.in}:${p.name}`, p]));
    for (const [pKey, baseParam] of baseParams) {
        const curParam = curParams.get(pKey);
        if (!curParam) {
            report('parameter-removed', `${key} ${pKey}`, 'parameter removed');
        } else if (!baseParam.required && curParam.required) {
            report('parameter-now-required', `${key} ${pKey}`, 'optional parameter became required');
        }
    }
    for (const [pKey, curParam] of curParams) {
        if (!baseParams.has(pKey) && curParam.required) {
            report('new-required-parameter', `${key} ${pKey}`, 'new required parameter added');
        }
    }

    // A response status a client already handles cannot vanish.
    for (const status of Object.keys(baseOp.responses ?? {})) {
        if (!(status in (curOp.responses ?? {}))) {
            report('response-removed', `${key} ${status}`, 'documented response status removed');
        }
    }

    if (baseOp.requestBody?.required !== true && curOp.requestBody?.required === true) {
        report('request-body-now-required', key, 'optional request body became required');
    }
}

// ------------------------------------------------------------------ schemas

const baseSchemas = baseline.components?.schemas ?? {};
const curSchemas = current.components?.schemas ?? {};

for (const [name, baseSchema] of Object.entries(baseSchemas)) {
    const curSchema = curSchemas[name];
    if (!curSchema) {
        report('schema-removed', `components.schemas.${name}`, 'schema removed');
        continue;
    }

    const baseNodes = new Map([...walk(baseSchema)]);
    const curNodes = new Map([...walk(curSchema)]);

    for (const [pointer, baseNode] of baseNodes) {
        const curNode = curNodes.get(pointer);
        const where = `components.schemas.${name}${pointer}`;
        if (curNode === undefined) {
            // Only flag the removal of a declared property, not of arbitrary
            // annotation keys such as description or examples.
            if (pointer.includes('/properties/')) {
                report('property-removed', where, 'property removed');
            }
            continue;
        }

        if (baseNode.type !== undefined && curNode.type !== undefined && String(baseNode.type) !== String(curNode.type)) {
            report('type-changed', where, `${JSON.stringify(baseNode.type)} -> ${JSON.stringify(curNode.type)}`);
        }

        if (Array.isArray(baseNode.enum) && Array.isArray(curNode.enum)) {
            const removed = baseNode.enum.filter((v) => !curNode.enum.includes(v));
            if (removed.length > 0) {
                report('enum-narrowed', where, `removed members: ${removed.join(', ')}`);
            }
        }

        if (Array.isArray(baseNode.required) && Array.isArray(curNode.required)) {
            const added = curNode.required.filter((v) => !baseNode.required.includes(v));
            if (added.length > 0) {
                report('new-required-property', where, `newly required: ${added.join(', ')}`);
            }
        } else if (!Array.isArray(baseNode.required) && Array.isArray(curNode.required) && curNode.required.length > 0) {
            report('new-required-property', where, `newly required: ${curNode.required.join(', ')}`);
        }

        // Tightening a bound rejects payloads a client is already sending.
        const tightened = [
            ['maxLength', (a, b) => b < a],
            ['maxItems', (a, b) => b < a],
            ['maximum', (a, b) => b < a],
            ['minLength', (a, b) => b > a],
            ['minItems', (a, b) => b > a],
            ['minimum', (a, b) => b > a],
        ];
        for (const [key, isTighter] of tightened) {
            if (typeof baseNode[key] === 'number' && typeof curNode[key] === 'number' && isTighter(baseNode[key], curNode[key])) {
                report('constraint-tightened', where, `${key}: ${baseNode[key]} -> ${curNode[key]}`);
            }
        }

        if (baseNode.additionalProperties !== false && curNode.additionalProperties === false) {
            report('additional-properties-closed', where, 'schema became closed to extra properties');
        }
    }
}

// ------------------------------------------------------------------- verdict

if (breaking.length > 0) {
    console.error(`\nBreaking contract changes against ${baseRef} (${breaking.length}):\n`);
    for (const { kind, where, detail } of breaking) {
        console.error(`  [${kind}] ${where}\n      ${detail}`);
    }
    console.error(
        '\nADR 0003: a removal, a type change, a narrowed enum, or a new required field\n' +
        'is breaking. It requires a new contract version and a dual-read migration,\n' +
        'not an edit in place. If this change is intentional and versioned, update the\n' +
        'baseline path or introduce /api/v2.\n'
    );
    process.exit(1);
}

console.log(`No breaking contract changes against ${baseRef}.`);
