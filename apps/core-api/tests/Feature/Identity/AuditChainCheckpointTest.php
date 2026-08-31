<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Contracts\AuditChainCheckpointStore;
use Modules\Audit\Contracts\VerifyAuditChain;
use Modules\Audit\Exceptions\AuditChainCheckpointFailed;
use Modules\Audit\Services\Checkpoint\AuditChainCheckpointVerifier;
use Modules\Audit\Services\Checkpoint\CreateAuditChainCheckpoint;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{secret: string, public: string}
 */
function sodiumCheckpointKeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [
        'secret' => bin2hex(sodium_crypto_sign_secretkey($pair)),
        'public' => bin2hex(sodium_crypto_sign_publickey($pair)),
    ];
}

/**
 * @param  array{secret: string, public: string}|null  $keys
 * @return array{secret: string, public: string}
 */
function configureAuditCheckpoints(?array $keys = null): array
{
    if (! extension_loaded('sodium')) {
        test()->markTestSkipped('sodium extension is required for audit chain checkpoints');
    }

    $pair = $keys ?? sodiumCheckpointKeyPair();
    Storage::fake('audit_checkpoints');
    config([
        'audit.checkpoint.enabled' => true,
        'audit.checkpoint.required' => true,
        'audit.checkpoint.disk' => 'audit_checkpoints',
        'audit.checkpoint.prefix' => 'checkpoints',
        'audit.checkpoint.key_id' => 'v1',
        'audit.checkpoint.private_key' => $pair['secret'],
        'audit.checkpoint.public_key' => $pair['public'],
        'audit.checkpoint.private_key_file' => '',
        'audit.checkpoint.public_key_file' => '',
    ]);

    return $pair;
}

function appendCheckpointAuditEvent(string $eventName, Identifier $userId): Identifier
{
    return app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($eventName, $userId): Identifier {
            return app(AppendAuditEvent::class)->append(
                $tx,
                $eventName,
                'user',
                $userId,
                ['reason_code' => 'checkpoint_test'],
                $userId,
                'user',
            );
        },
    );
}

function rehashAuditChainAsOwner(string $fromEventName, string $toEventName): void
{
    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::update(
        'UPDATE audit_events SET event_name = ? WHERE event_name = ?',
        [$toEventName, $fromEventName],
    );
    DB::statement(<<<'SQL'
        DO $rehash$
        DECLARE
            r record;
            v_previous bytea := NULL;
            v_previous_hex text := '';
            v_occurred_text text;
            v_metadata_text text;
            v_canonical text;
            v_row_hash bytea;
        BEGIN
            FOR r IN
                SELECT id, event_name, actor_id, actor_type, object_type, object_id, metadata, occurred_at
                FROM public.audit_events
                ORDER BY chain_sequence
            LOOP
                v_occurred_text := pg_catalog.to_char(
                    r.occurred_at AT TIME ZONE 'UTC',
                    'YYYY-MM-DD HH24:MI:SS.US'
                ) || '+00:00';
                v_metadata_text := pg_catalog.replace(
                    public.clinic_audit_canonical_metadata(COALESCE(r.metadata, '{}'::jsonb)),
                    '/',
                    E'\\/'
                );
                v_canonical := v_previous_hex
                    || '|' || r.id::text
                    || '|' || COALESCE(r.event_name, '')
                    || '|' || COALESCE(r.object_type, '')
                    || '|' || r.object_id::text
                    || '|' || COALESCE(r.actor_id::text, '')
                    || '|' || COALESCE(r.actor_type, '')
                    || '|' || v_metadata_text
                    || '|' || v_occurred_text;
                v_row_hash := public.digest(pg_catalog.convert_to(v_canonical, 'UTF8'), 'sha256');

                UPDATE public.audit_events
                   SET previous_hash = v_previous,
                       row_hash = v_row_hash
                 WHERE id = r.id;

                v_previous := v_row_hash;
                v_previous_hex := pg_catalog.encode(v_row_hash, 'hex');
            END LOOP;
        END
        $rehash$;
        SQL);
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');
}

function latestCheckpointPath(): string
{
    $files = Storage::disk('audit_checkpoints')->allFiles('checkpoints');
    expect($files)->not->toBeEmpty();

    return (string) $files[array_key_last($files)];
}

it('skips external checkpoint checks when checkpointing is disabled', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_disabled', $userId);

    $result = app(VerifyAuditChain::class)->verify();

    expect($result['ok'])->toBeTrue()
        ->and($result['checkpoint_ok'])->toBeNull();
});

it('no-ops checkpoint creation when the chain is empty', function () {
    configureAuditCheckpoints();

    if ((int) DB::table('audit_events')->count() > 0) {
        DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
        DB::table('audit_events')->delete();
        DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');
    }

    expect((int) DB::table('audit_events')->count())->toBe(0);

    $this->artisan('audit:checkpoint-chain')->assertSuccessful();

    expect(app(AuditChainCheckpointStore::class)->all())->toBe([])
        ->and(app(VerifyAuditChain::class)->verify())->toMatchArray([
            'ok' => true,
            'checkpoint_ok' => true,
            'checkpoint_reason' => null,
        ]);
});

it('accepts a valid signed checkpoint', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_valid', $userId);

    $created = app(CreateAuditChainCheckpoint::class)->create();
    $result = app(VerifyAuditChain::class)->verify();

    expect($created['created'])->toBeTrue()
        ->and($created['sequence'])->toBeInt()
        ->and($result['ok'])->toBeTrue()
        ->and($result['checkpoint_ok'])->toBeTrue()
        ->and($result['checkpoint_reason'])->toBeNull()
        ->and(app(AuditChainCheckpointStore::class)->all())->toHaveCount(1);

    $this->artisan('audit:verify-chain')->assertSuccessful();
});

it('rejects a modified checkpoint payload', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_payload', $userId);
    app(CreateAuditChainCheckpoint::class)->create();

    $path = latestCheckpointPath();
    $envelope = json_decode((string) Storage::disk('audit_checkpoints')->get($path), true, 8, JSON_THROW_ON_ERROR);
    $payload = json_decode((string) $envelope['payload'], true, 8, JSON_THROW_ON_ERROR);
    $payload['row_hash'] = str_repeat('ab', 32);
    ksort($payload);
    $envelope['payload'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    Storage::disk('audit_checkpoints')->put($path, json_encode($envelope, JSON_THROW_ON_ERROR));

    $result = app(VerifyAuditChain::class)->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['checkpoint_ok'])->toBeFalse()
        ->and($result['checkpoint_reason'])->toBe(AuditChainCheckpointVerifier::REASON_SIGNATURE_INVALID);
});

it('rejects a modified checkpoint signature', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_sig', $userId);
    app(CreateAuditChainCheckpoint::class)->create();

    $path = latestCheckpointPath();
    $envelope = json_decode((string) Storage::disk('audit_checkpoints')->get($path), true, 8, JSON_THROW_ON_ERROR);
    $signature = base64_decode((string) $envelope['signature'], true);
    expect($signature)->toBeString()->and(strlen((string) $signature))->toBe(SODIUM_CRYPTO_SIGN_BYTES);
    $last = strlen((string) $signature) - 1;
    $signature[$last] = chr(ord($signature[$last]) ^ 0xFF);
    $envelope['signature'] = base64_encode((string) $signature);
    Storage::disk('audit_checkpoints')->put($path, json_encode($envelope, JSON_THROW_ON_ERROR));

    $result = app(VerifyAuditChain::class)->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['checkpoint_ok'])->toBeFalse()
        ->and($result['checkpoint_reason'])->toBe(AuditChainCheckpointVerifier::REASON_SIGNATURE_INVALID);
});

it('rejects a checkpoint verified with the wrong public key', function () {
    $keys = configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_wrong_key', $userId);
    app(CreateAuditChainCheckpoint::class)->create();

    $other = sodiumCheckpointKeyPair();
    config(['audit.checkpoint.public_key' => $other['public']]);

    $result = app(VerifyAuditChain::class)->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['checkpoint_ok'])->toBeFalse()
        ->and($result['checkpoint_reason'])->toBe(AuditChainCheckpointVerifier::REASON_SIGNATURE_INVALID)
        ->and($keys['public'])->not->toBe($other['public']);
});

it('rejects a checkpoint whose sequence row is missing', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_keep', $userId);
    appendCheckpointAuditEvent('test.audit.checkpoint_drop', $userId);
    $created = app(CreateAuditChainCheckpoint::class)->create();

    $row = DB::table('audit_events')->where('chain_sequence', $created['sequence'])->first();
    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::table('audit_events')->where('id', $row->id)->delete();
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    $result = app(VerifyAuditChain::class)->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['checkpoint_ok'])->toBeFalse()
        ->and($result['checkpoint_reason'])->toBe(AuditChainCheckpointVerifier::REASON_ROW_MISSING);
});

it('rejects a checkpoint whose persisted row hash differs', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_hash_diff', $userId);
    $created = app(CreateAuditChainCheckpoint::class)->create();

    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::update(
        'UPDATE audit_events SET row_hash = ?::bytea WHERE chain_sequence = ?',
        [BinaryColumn::bind(random_bytes(32)), $created['sequence']],
    );
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    $result = app(VerifyAuditChain::class)->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['checkpoint_ok'])->toBeFalse()
        ->and($result['checkpoint_reason'])->toBe(AuditChainCheckpointVerifier::REASON_HASH_MISMATCH);
});

it('rejects a fully rehashed internally consistent database', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_rehash_a', $userId);
    appendCheckpointAuditEvent('test.audit.checkpoint_rehash_b', $userId);
    app(CreateAuditChainCheckpoint::class)->create();

    expect(app(VerifyAuditChain::class)->verify()['ok'])->toBeTrue();

    rehashAuditChainAsOwner('test.audit.checkpoint_rehash_a', 'test.audit.checkpoint_rehash_forged');

    $database = app(VerifyAuditChain::class)->verifyDatabaseChain();
    $result = app(VerifyAuditChain::class)->verify();

    expect($database['ok'])->toBeTrue()
        ->and($result['ok'])->toBeFalse()
        ->and($result['checkpoint_ok'])->toBeFalse()
        ->and($result['checkpoint_reason'])->toBe(AuditChainCheckpointVerifier::REASON_HASH_MISMATCH);
});

it('accepts legitimate events appended after a checkpoint', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_before', $userId);
    $first = app(CreateAuditChainCheckpoint::class)->create();
    appendCheckpointAuditEvent('test.audit.checkpoint_after', $userId);
    $second = app(CreateAuditChainCheckpoint::class)->create();
    $result = app(VerifyAuditChain::class)->verify();

    expect($result['ok'])->toBeTrue()
        ->and($result['checkpoint_ok'])->toBeTrue()
        ->and($second['sequence'])->toBeGreaterThan((int) $first['sequence'])
        ->and(app(AuditChainCheckpointStore::class)->all())->toHaveCount(2);
});

it('rejects a malformed checkpoint object', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_malformed', $userId);

    Storage::disk('audit_checkpoints')->put('checkpoints/not-a-checkpoint.json', '{');

    $result = app(VerifyAuditChain::class)->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['checkpoint_ok'])->toBeFalse()
        ->and($result['checkpoint_reason'])->toBe(AuditChainCheckpointVerifier::REASON_MALFORMED);
});

it('rejects verification when a required checkpoint is missing', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_missing', $userId);

    $result = app(VerifyAuditChain::class)->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['checkpoint_ok'])->toBeFalse()
        ->and($result['checkpoint_reason'])->toBe(AuditChainCheckpointVerifier::REASON_MISSING);

    $this->artisan('audit:verify-chain')->assertFailed();
});

it('refuses to checkpoint an already invalid database chain', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    $id = appendCheckpointAuditEvent('test.audit.checkpoint_invalid', $userId);

    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::table('audit_events')->where('id', $id->value)->update(['event_name' => 'test.audit.checkpoint_tampered']);
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    $this->artisan('audit:checkpoint-chain')->assertFailed();

    expect(app(AuditChainCheckpointStore::class)->all())->toBe([]);
});

it('never writes the private key into command output or audit rows', function () {
    $keys = configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_noleak', $userId);

    $this->artisan('audit:checkpoint-chain')->assertSuccessful();
    $checkpointOutput = Artisan::output();
    $this->artisan('audit:verify-chain')->assertSuccessful();
    $verifyOutput = Artisan::output();

    $metadata = json_encode(DB::table('audit_events')->get(['event_name', 'metadata', 'actor_id', 'object_id'])->all());

    expect($checkpointOutput)->not->toContain($keys['secret'])
        ->and($verifyOutput)->not->toContain($keys['secret'])
        ->and($metadata)->not->toContain($keys['secret'])
        ->and($checkpointOutput)->not->toContain($keys['public']);
});

it('keeps checkpoint private keys out of PostgreSQL for application roles', function () {
    $keys = configureAuditCheckpoints();

    $columns = DB::select("
        SELECT table_schema, table_name, column_name
        FROM information_schema.columns
        WHERE table_schema IN ('public', 'reporting')
          AND (
            column_name ILIKE '%checkpoint%'
            OR column_name ILIKE '%ed25519%'
            OR column_name ILIKE '%signing_key%'
            OR column_name ILIKE '%private_key%'
          )
    ");
    $routines = DB::select("
        SELECT p.proname
        FROM pg_proc p
        JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname IN ('public', 'reporting')
          AND (
            p.proname ILIKE '%checkpoint%'
            OR pg_get_function_identity_arguments(p.oid) ILIKE '%private_key%'
            OR pg_get_function_identity_arguments(p.oid) ILIKE '%ed25519%'
          )
    ");

    expect($columns)->toBe([])
        ->and($routines)->toBe([])
        ->and($keys['secret'])->not->toBe('');

    foreach (['clinic_app', 'clinic_worker', 'clinic_audit_writer', 'clinic_reporter'] as $role) {
        $exists = DB::selectOne('SELECT 1 AS ok FROM pg_roles WHERE rolname = ?', [$role]);
        if ($exists === null) {
            continue;
        }

        expect($keys['secret'])->not->toContain($role);
    }
});

it('leaves the audit chain unchanged when checkpoint storage fails', function () {
    configureAuditCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    appendCheckpointAuditEvent('test.audit.checkpoint_store_fail', $userId);
    $before = DB::table('audit_events')->orderBy('chain_sequence')->get(['id', 'chain_sequence', 'event_name']);

    $this->app->instance(AuditChainCheckpointStore::class, new class implements AuditChainCheckpointStore
    {
        public function put(string $name, string $contents): void
        {
            throw new AuditChainCheckpointFailed('store down', 'checkpoint_store_unavailable');
        }

        public function exists(string $name): bool
        {
            return false;
        }

        public function all(): array
        {
            return [];
        }
    });
    $this->app->forgetInstance(CreateAuditChainCheckpoint::class);

    $this->artisan('audit:checkpoint-chain')->assertFailed();

    $after = DB::table('audit_events')->orderBy('chain_sequence')->get(['id', 'chain_sequence', 'event_name']);

    expect($after->all())->toEqual($before->all())
        ->and(Storage::disk('audit_checkpoints')->allFiles('checkpoints'))->toBe([]);
});
