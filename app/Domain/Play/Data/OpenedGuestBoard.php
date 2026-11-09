<?php

namespace App\Domain\Play\Data;

use App\Domain\Play\Models\GuestBoard;

/**
 * What `OpenGuestBoard` hands back once: the board and its bearer token
 * `{board_id}.{secret}`. The secret is never stored or shown again; the
 * board's tab keeps the token in `sessionStorage` (docs/architecture.md §4).
 */
final readonly class OpenedGuestBoard
{
    public function __construct(
        public GuestBoard $board,
        #[\SensitiveParameter]
        public string $token,
    ) {}
}
