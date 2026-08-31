<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('documents audit checkpoints, firebase, and backup artefacts in the live inventory', function () {
    $inventory = (string) file_get_contents(dirname(base_path(), 2).'/docs/data-classification/data-inventory.md');

    expect($inventory)->toBeString()
        ->and($inventory)->toContain('### Audit checkpoint files')
        ->and($inventory)->toContain('audit_checkpoints')
        ->and($inventory)->toContain('### Firebase / FCM')
        ->and($inventory)->toContain('### Backup artefacts')
        ->and($inventory)->toContain('OPEN_LEGAL_DECISION')
        ->and($inventory)->toContain('OPERATIONAL_FOLLOW_THROUGH')
        ->and($inventory)->toContain('Subject erasure does NOT imply immediate mutation of immutable historical backups');
});
