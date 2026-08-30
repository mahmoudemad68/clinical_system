<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Contracts\VerifyAuditChain;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function auditRolePdo(string $username): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        (string) env('DB_HOST', '127.0.0.1'),
        (string) env('DB_PORT', '5432'),
        (string) env('DB_DATABASE', 'clinic_test'),
    );

    return new PDO($dsn, $username, (string) env('DB_AUDIT_PASSWORD', 'local_dev_only_not_a_secret'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function auditRoleDenied(callable $action): string
{
    try {
        $action();
    } catch (PDOException $e) {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }

    return '';
}

function auditDirectInsertSql(): string
{
    return 'INSERT INTO audit_events (
        id, event_name, actor_id, actor_type, object_type, object_id,
        metadata, previous_hash, row_hash, chain_sequence, occurred_at
    ) VALUES (
        ?, ?, NULL, NULL, ?, ?, ?::jsonb, ?::bytea, ?::bytea, ?, NOW()
    )';
}

function skipUnlessRole(string $role): void
{
    $exists = DB::selectOne('SELECT 1 AS ok FROM pg_roles WHERE rolname = ?', [$role]);
    if ($exists === null) {
        test()->markTestSkipped($role.' is not present on this cluster');
    }
}

it('denies clinic_audit_writer a direct table insert', function () {
    skipUnlessRole('clinic_audit_writer');
    $pdo = auditRolePdo('clinic_audit_writer');
    $id = app(IdentityGenerator::class)->next()->value;
    $hash = BinaryColumn::bind(random_bytes(32));

    $state = auditRoleDenied(function () use ($pdo, $id, $hash): void {
        $stmt = $pdo->prepare(auditDirectInsertSql());
        $stmt->execute([$id, 'test.forged.insert', 'user', $id, '{"reason_code":"forged"}', $hash, $hash, 9_000_001]);
    });

    expect($state)->toBe('42501')
        ->and(DB::table('audit_events')->where('id', $id)->exists())->toBeFalse();
});

it('denies clinic_audit_writer an update of audit_events', function () {
    skipUnlessRole('clinic_audit_writer');
    $pdo = auditRolePdo('clinic_audit_writer');

    $state = auditRoleDenied(function () use ($pdo): void {
        $pdo->exec("UPDATE audit_events SET event_name = 'forged.update' WHERE false");
    });

    expect($state)->toBe('42501');
});

it('denies clinic_audit_writer a delete from audit_events', function () {
    skipUnlessRole('clinic_audit_writer');
    $pdo = auditRolePdo('clinic_audit_writer');

    $state = auditRoleDenied(function () use ($pdo): void {
        $pdo->exec('DELETE FROM audit_events WHERE false');
    });

    expect($state)->toBe('42501');
});

it('appends through the definer function for the audit writer', function () {
    skipUnlessRole('clinic_audit_writer');
    $user = User::factory()->create();
    $userId = (string) $user->id;
    $eventId = app(IdentityGenerator::class)->next()->value;
    $pdo = auditRolePdo('clinic_audit_writer');
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT clinic_append_audit_event(?::uuid, ?, ?::uuid, ?, ?, ?::uuid, ?::jsonb, ?::timestamptz)',
    );
    $stmt->execute([
        $eventId,
        'test.audit.writer_append',
        $userId,
        'user',
        'user',
        $userId,
        '{"reason_code":"writer"}',
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP'),
    ]);

    $row = $pdo->query("SELECT event_name, chain_sequence, row_hash FROM audit_events WHERE id = '{$eventId}'")->fetch(PDO::FETCH_OBJ);
    $pdo->rollBack();

    expect($row)->not->toBeFalse()
        ->and((string) $row->event_name)->toBe('test.audit.writer_append')
        ->and($row->row_hash)->not->toBeNull()
        ->and((int) $row->chain_sequence)->toBeGreaterThan(0)
        ->and(DB::table('audit_events')->where('id', $eventId)->exists())->toBeFalse();
});

it('rejects the legacy hash-taking append signature', function () {
    skipUnlessRole('clinic_audit_writer');
    $pdo = auditRolePdo('clinic_audit_writer');
    $id = app(IdentityGenerator::class)->next()->value;
    $hash = BinaryColumn::bind(random_bytes(32));

    $state = auditRoleDenied(function () use ($pdo, $id, $hash): void {
        $stmt = $pdo->prepare(
            'SELECT clinic_append_audit_event(?::uuid, ?, ?::uuid, ?, ?, ?::uuid, ?::jsonb, ?::bytea, ?::bytea, ?, ?::timestamptz)',
        );
        $stmt->execute([
            $id,
            'test.forged.legacy',
            $id,
            'user',
            'user',
            $id,
            '{"reason_code":"forged"}',
            $hash,
            $hash,
            9_000_002,
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP'),
        ]);
    });

    expect($state)->toBe('42883')
        ->and(DB::table('audit_events')->where('id', $id)->exists())->toBeFalse();
});

it('stores a database-derived previous hash rather than a caller value', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    $firstId = null;
    $secondId = null;

    app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($userId, &$firstId, &$secondId): void {
            $firstId = app(AppendAuditEvent::class)->append(
                $tx,
                'test.audit.first',
                'user',
                $userId,
                ['reason_code' => 'first'],
                $userId,
                'user',
            );
            $secondId = app(AppendAuditEvent::class)->append(
                $tx,
                'test.audit.second',
                'user',
                $userId,
                ['reason_code' => 'second'],
                $userId,
                'user',
            );
        },
    );

    $first = DB::table('audit_events')->where('id', $firstId->value)->first();
    $second = DB::table('audit_events')->where('id', $secondId->value)->first();
    $firstHash = BinaryColumn::asString($first->row_hash);
    $secondPrevious = $second->previous_hash === null ? '' : BinaryColumn::asString($second->previous_hash);

    expect((int) $second->chain_sequence)->toBe((int) $first->chain_sequence + 1)
        ->and($secondPrevious)->toBe($firstHash)
        ->and($secondPrevious)->not->toBe('')
        ->and(app(VerifyAuditChain::class)->verify()['ok'])->toBeTrue();
});

it('stores a database-derived row hash that the verifier accepts', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    $forged = hash('sha256', 'caller-chosen-row-hash', true);

    $id = app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($userId): Identifier {
            return app(AppendAuditEvent::class)->append(
                $tx,
                'test.audit.hash_owner',
                'user',
                $userId,
                ['reason_code' => 'hash_owner'],
                $userId,
                'user',
            );
        },
    );

    $row = DB::table('audit_events')->where('id', $id->value)->first();
    $stored = BinaryColumn::asString($row->row_hash);

    expect($stored)->not->toBe($forged)
        ->and(strlen($stored))->toBe(32)
        ->and(app(VerifyAuditChain::class)->verify())->toMatchArray([
            'ok' => true,
            'first_bad_sequence' => null,
        ]);
});

it('rejects a clinic_app direct insert that would bypass the function', function () {
    skipUnlessRole('clinic_app');
    $pdo = auditRolePdo('clinic_app');
    $id = app(IdentityGenerator::class)->next()->value;
    $hash = BinaryColumn::bind(random_bytes(32));

    $state = auditRoleDenied(function () use ($pdo, $id, $hash): void {
        $stmt = $pdo->prepare(auditDirectInsertSql());
        $stmt->execute([$id, 'test.forged.app', 'user', $id, '{"reason_code":"forged"}', $hash, $hash, 9_000_003]);
    });

    expect($state)->toBe('42501');
});

it('rejects a clinic_worker direct insert that would bypass the function', function () {
    skipUnlessRole('clinic_worker');
    $pdo = auditRolePdo('clinic_worker');
    $id = app(IdentityGenerator::class)->next()->value;
    $hash = BinaryColumn::bind(random_bytes(32));

    $state = auditRoleDenied(function () use ($pdo, $id, $hash): void {
        $stmt = $pdo->prepare(auditDirectInsertSql());
        $stmt->execute([$id, 'test.forged.worker', 'user', $id, '{"reason_code":"forged"}', $hash, $hash, 9_000_004]);
    });

    expect($state)->toBe('42501');
});

it('rejects a clinic_worker execute of the append function', function () {
    skipUnlessRole('clinic_worker');
    $pdo = auditRolePdo('clinic_worker');
    $id = app(IdentityGenerator::class)->next()->value;

    $state = auditRoleDenied(function () use ($pdo, $id): void {
        $stmt = $pdo->prepare(
            'SELECT clinic_append_audit_event(?::uuid, ?, ?::uuid, ?, ?, ?::uuid, ?::jsonb, ?::timestamptz)',
        );
        $stmt->execute([
            $id,
            'test.forged.worker_exec',
            $id,
            'user',
            'user',
            $id,
            '{"reason_code":"forged"}',
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP'),
        ]);
    });

    expect($state)->toBe('42501');
});

it('blocks the table owner from updating a stored audit row', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    $id = app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($userId): Identifier {
            return app(AppendAuditEvent::class)->append(
                $tx,
                'test.audit.immutable',
                'user',
                $userId,
                ['reason_code' => 'immutable'],
                $userId,
                'user',
            );
        },
    );

    expect(fn () => DB::table('audit_events')->where('id', $id->value)->update(['event_name' => 'tampered']))
        ->toThrow(QueryException::class, 'audit_events is append-only');
});

it('detects a tampered event name through the chain verifier', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    $id = app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($userId): Identifier {
            return app(AppendAuditEvent::class)->append(
                $tx,
                'test.audit.tamper_name',
                'user',
                $userId,
                ['reason_code' => 'tamper'],
                $userId,
                'user',
            );
        },
    );

    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::table('audit_events')->where('id', $id->value)->update(['event_name' => 'test.audit.tampered']);
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    $result = app(VerifyAuditChain::class)->verify();
    expect($result['ok'])->toBeFalse()
        ->and($result['first_bad_sequence'])->not->toBeNull();
});

it('detects a broken previous hash through the chain verifier', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);

    app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($userId): void {
            app(AppendAuditEvent::class)->append($tx, 'test.audit.link_a', 'user', $userId, ['reason_code' => 'a'], $userId, 'user');
            app(AppendAuditEvent::class)->append($tx, 'test.audit.link_b', 'user', $userId, ['reason_code' => 'b'], $userId, 'user');
        },
    );

    $second = DB::table('audit_events')->where('event_name', 'test.audit.link_b')->first();
    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::update(
        'UPDATE audit_events SET previous_hash = ?::bytea WHERE id = ?',
        [BinaryColumn::bind(random_bytes(32)), $second->id],
    );
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    $result = app(VerifyAuditChain::class)->verify();
    expect($result['ok'])->toBeFalse()
        ->and((int) $result['first_bad_sequence'])->toBe((int) $second->chain_sequence);
});

it('detects a forked predecessor through the chain verifier', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);

    app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($userId): void {
            app(AppendAuditEvent::class)->append($tx, 'test.audit.fork_a', 'user', $userId, ['reason_code' => 'a'], $userId, 'user');
            app(AppendAuditEvent::class)->append($tx, 'test.audit.fork_b', 'user', $userId, ['reason_code' => 'b'], $userId, 'user');
        },
    );

    $first = DB::table('audit_events')->where('event_name', 'test.audit.fork_a')->first();
    $forkId = app(IdentityGenerator::class)->next()->value;
    $seq = (int) DB::table('audit_events')->max('chain_sequence') + 1;

    DB::insert(
        'INSERT INTO audit_events (
            id, event_name, actor_id, actor_type, object_type, object_id,
            metadata, previous_hash, row_hash, chain_sequence, occurred_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?::bytea, ?::bytea, ?, NOW())',
        [
            $forkId,
            'test.audit.fork_c',
            (string) $user->id,
            'user',
            'user',
            (string) $user->id,
            '{"reason_code":"fork"}',
            $first->previous_hash === null ? null : BinaryColumn::bind(BinaryColumn::asString($first->previous_hash)),
            BinaryColumn::bind(random_bytes(32)),
            $seq,
        ],
    );

    $result = app(VerifyAuditChain::class)->verify();
    expect($result['ok'])->toBeFalse();
});

it('fails the verify-chain command after a content change', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    $id = app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($userId): Identifier {
            return app(AppendAuditEvent::class)->append(
                $tx,
                'test.audit.command_ok',
                'user',
                $userId,
                ['reason_code' => 'command'],
                $userId,
                'user',
            );
        },
    );

    $this->artisan('audit:verify-chain')->assertSuccessful();

    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::table('audit_events')->where('id', $id->value)->update(['event_name' => 'test.audit.command_bad']);
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    $this->artisan('audit:verify-chain')->assertFailed();
});
