<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\VerifyAuditChain;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\ConcurrentAuditAppend;

uses(CommittedDatabaseTestCase::class);

it('keeps one linear chain when two connections append concurrently', function () {
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_audit_writer'");
    if ($role === null) {
        $this->markTestSkipped('clinic_audit_writer is not present on this cluster');
    }

    $user = User::factory()->create();
    $ids = app(IdentityGenerator::class);
    $leftId = $ids->next()->value;
    $rightId = $ids->next()->value;
    $occurred = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP');

    $pair = ConcurrentAuditAppend::run(
        [
            'id' => $leftId,
            'event_name' => 'test.audit.concurrent_left',
            'actor_id' => (string) $user->id,
            'actor_type' => 'user',
            'object_type' => 'user',
            'object_id' => (string) $user->id,
            'metadata' => '{"reason_code":"concurrent_left"}',
            'occurred_at' => $occurred,
        ],
        [
            'id' => $rightId,
            'event_name' => 'test.audit.concurrent_right',
            'actor_id' => (string) $user->id,
            'actor_type' => 'user',
            'object_type' => 'user',
            'object_id' => (string) $user->id,
            'metadata' => '{"reason_code":"concurrent_right"}',
            'occurred_at' => $occurred,
        ],
    );

    expect($pair['left']['ok'] ?? false)->toBeTrue()
        ->and($pair['right']['ok'] ?? false)->toBeTrue()
        ->and($pair['left']['sqlstate'] ?? null)->toBeNull()
        ->and($pair['right']['sqlstate'] ?? null)->toBeNull();

    $rows = DB::table('audit_events')
        ->whereIn('id', [$leftId, $rightId])
        ->orderBy('chain_sequence')
        ->get();

    expect($rows)->toHaveCount(2);

    $first = $rows[0];
    $second = $rows[1];
    $firstHash = BinaryColumn::asString($first->row_hash);
    $secondPrevious = $second->previous_hash === null ? null : BinaryColumn::asString($second->previous_hash);

    expect((int) $second->chain_sequence)->toBe((int) $first->chain_sequence + 1)
        ->and($secondPrevious)->toBe($firstHash)
        ->and($first->chain_sequence)->not->toBe($second->chain_sequence);

    $predecessors = $rows->map(function ($row): string {
        return $row->previous_hash === null ? 'genesis' : bin2hex(BinaryColumn::asString($row->previous_hash));
    })->all();
    expect($predecessors)->toHaveCount(2)
        ->and($predecessors[0])->not->toBe($predecessors[1]);

    $result = app(VerifyAuditChain::class)->verify();
    expect($result['ok'])->toBeTrue()
        ->and($result['checked'])->toBeGreaterThan(1);
});
