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
| Phase 01 logout/revoke is authoritative on HTTP (hashed tokens and session
| rows). When Phase 04 adds subscriptions, channel authorization must deny a
| revoked session before any event is delivered. Measured socket-close SLO
| remains G-01-16 OPEN.
|
*/

Broadcast::channel('platform.health', static fn (): bool => false);
