<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Pennant Store
    |--------------------------------------------------------------------------
    |
    | Server-owned feature flags (Phase 00). The array store is for tests.
    | Production uses the database so a flag change is an audited row, not an
    | environment-variable deploy that nobody can reconstruct.
    |
    | V1 exclusions are defined in code as always-false. A database row cannot
    | turn them on: the resolver returns false regardless of stored state.
    |
    */

    'default' => env('PENNANT_STORE', 'database'),

    'stores' => [

        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'features',
        ],

    ],

];
