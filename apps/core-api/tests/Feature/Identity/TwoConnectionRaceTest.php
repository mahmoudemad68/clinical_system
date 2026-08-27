<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function clinicPdo(): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        (string) env('DB_HOST', '127.0.0.1'),
        (string) env('DB_PORT', '5432'),
        (string) env('DB_DATABASE', 'clinic_test'),
    );

    return new PDO($dsn, (string) env('DB_USERNAME'), (string) env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function insertCommittedUser(PDO $pdo): string
{
    $id = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c10';
    $pdo->exec("DELETE FROM contextual_access_grants WHERE actor_user_id = '{$id}'");
    $pdo->exec("DELETE FROM users WHERE id = '{$id}'");
    $enc = '\\x'.bin2hex(random_bytes(32));
    $hmac = '\\x'.bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        'INSERT INTO users (
            id, name, phone_e164_encrypted, phone_lookup_hmac, phone_key_version,
            password_hash, account_type, status, language, credential_version,
            phone_verified_at, bootstrap_exempt, created_at, updated_at
        ) VALUES (
            ?, ?, ?::bytea, ?::bytea, 1, ?, ?, ?, ?, 1, NOW(), false, NOW(), NOW()
        )',
    );
    $stmt->execute([$id, 'Race User', $enc, $hmac, 'x', 'patient', 'active', 'en']);

    return $id;
}

afterEach(function (): void {
    $pdo = clinicPdo();
    $pdo->exec("DELETE FROM contextual_access_grants WHERE reason_code = 'race'");
    $pdo->exec("DELETE FROM users WHERE id = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c10'");
    $pdo->exec("DELETE FROM auth_refresh_consumptions WHERE family_id = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01'");
});

it('serializes two connections inserting the same consumed refresh hash', function () {
    $family = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01';
    $hex = '\\x'.bin2hex(random_bytes(32));
    $pdo1 = clinicPdo();
    $pdo2 = clinicPdo();
    $pdo1->beginTransaction();
    $stmt = $pdo1->prepare('INSERT INTO auth_refresh_consumptions (family_id, token_hash, generation, consumed_at) VALUES (?, ?::bytea, 1, NOW())');
    $stmt->execute([$family, $hex]);

    $pdo2->exec("SET lock_timeout = '500ms'");
    $blocked = false;
    try {
        $dup = $pdo2->prepare('INSERT INTO auth_refresh_consumptions (family_id, token_hash, generation, consumed_at) VALUES (?, ?::bytea, 1, NOW())');
        $dup->execute([$family, $hex]);
    } catch (PDOException) {
        $blocked = true;
    }
    $pdo1->rollBack();

    expect($blocked)->toBeTrue();
});

it('serializes two connections inserting the same grant tuple', function () {
    $setup = clinicPdo();
    $userId = insertCommittedUser($setup);
    $resource = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c02';
    $context = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c03';
    $grantA = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c04';
    $grantB = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c05';
    $pdo1 = clinicPdo();
    $pdo2 = clinicPdo();
    $sql = 'INSERT INTO contextual_access_grants (id, actor_user_id, capability, resource_type, resource_id, context_type, context_id, reason_code, issued_by_type, issued_by_id, version, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())';
    $pdo1->beginTransaction();
    $stmt = $pdo1->prepare($sql);
    $stmt->execute([$grantA, $userId, 'access.context.delegate', 'auth_session', $resource, 'self', $context, 'race', 'system', $userId]);
    $pdo2->exec("SET lock_timeout = '500ms'");
    $blocked = false;
    try {
        $dup = $pdo2->prepare($sql);
        $dup->execute([$grantB, $userId, 'access.context.delegate', 'auth_session', $resource, 'self', $context, 'race', 'system', $userId]);
    } catch (PDOException) {
        $blocked = true;
    }
    $pdo1->rollBack();

    expect($blocked)->toBeTrue();
});

it('holds the audit chain advisory lock across a second connection', function () {
    $pdo1 = clinicPdo();
    $pdo2 = clinicPdo();
    $pdo1->beginTransaction();
    $pdo1->query("SELECT pg_advisory_xact_lock(hashtext('audit_events_chain'))");
    $pdo2->exec("SET lock_timeout = '500ms'");
    $blocked = false;
    try {
        $pdo2->query("SELECT pg_advisory_xact_lock(hashtext('audit_events_chain'))");
    } catch (PDOException) {
        $blocked = true;
    }
    $pdo1->commit();

    expect($blocked)->toBeTrue();
});
