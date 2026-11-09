<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\OpenedGuestBoard;
use App\Domain\Play\Models\GuestBoard;
use App\Domain\Play\Support\PairingCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * `/lovhe` opens a guest board (docs/architecture.md §4): a row with a
 * pairing code and a random 32-byte secret. The secret leaves the server
 * once, in the answer to this request, inside the bearer token; the row
 * keeps only its hash. The board lives `elifba.guest_board_hours`; unclaimed,
 * it dies with its code.
 */
final readonly class OpenGuestBoard
{
    public function __construct(private PairingCode $codes) {}

    public function handle(): OpenedGuestBoard
    {
        $secret = GuestBoard::newSecret();

        $board = $this->codes->issue(
            static fn (string $code, CarbonImmutable $codeExpiresAt): GuestBoard => DB::transaction(static function () use ($secret, $code, $codeExpiresAt): GuestBoard {
                $board = new GuestBoard;
                $board->code = $code;
                $board->code_expires_at = $codeExpiresAt;
                $board->secret_hash = GuestBoard::hashSecret($secret);
                $board->expires_at = CarbonImmutable::now()->addHours(Config::integer('elifba.guest_board_hours'));
                $board->save();

                return $board;
            }),
        );

        return new OpenedGuestBoard($board, $board->id.'.'.$secret);
    }
}
