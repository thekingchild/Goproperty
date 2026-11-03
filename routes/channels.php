<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| Private channels are authorized at POST /broadcasting/auth (VPS profile
| only: shared hosting broadcasts nothing, ARCHITECTURE.md § 12.1). Each
| module registers its own channels from its service provider, e.g. the
| Building Group's `group.{group}` (ARCHITECTURE.md § 6.8).
|
*/
