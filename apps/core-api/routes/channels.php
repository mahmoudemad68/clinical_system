<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels — deny by default
|--------------------------------------------------------------------------
|
| Phase 00 scaffolds private-channel authorization. No client is authorized
| to subscribe until Phase 04 supplies actor-scoped queue channels. A
| channel name is not authorization (invariant 13).
|
*/

Broadcast::channel('platform.health', static fn (): bool => false);
