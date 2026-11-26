<?php

namespace App\Domain\Rooms\Support\Domino;

/**
 * One act of a domino game as `DominoGame` replays it (docs/architecture.md
 * §12 "Domino", ADR 038 decisions 16–17): a `room_acts` row of kind
 * `play`, `yield` or `back` — what was done, at which ply, a play's tile
 * and end as stored, and when the server received it.
 *
 * A turn act names no seat: whose it is follows from the game — the seat
 * to move at its ply (decision 17). What the replay needs of its sender is
 * whether a device sent it or the server played it (`stoodIn`: a row
 * without a player). A `back` is no turn: it names the seat whose player
 * came back. Drawing and passing are never acts; neither is a presence —
 * a seat whose player left, or the computer's — which the replay is given
 * beside the acts.
 */
final readonly class DominoAct
{
    public const string PLAY = 'play';

    public const string YIELD = 'yield';

    public const string BACK = 'back';

    /**
     * @param  'play'|'yield'|'back'  $kind
     * @param  int  $ply  the turn acts the game held when it was sent; for a `back`, the game's ply then
     * @param  list<string>|null  $path  a play's `[tile]` for a hand's first tile, else `[tile, end]`, as stored; null for the other kinds
     * @param  int|null  $seat  a `back`'s seat; null for a turn act
     * @param  bool  $stoodIn  a turn act the server made: no device sent it
     */
    public function __construct(
        public string $kind,
        public int $ply,
        public ?array $path,
        public ?int $seat,
        public bool $stoodIn,
        public int $actedMs,
    ) {}

    /**
     * A play as stored, from a device, or the server's for the seat to move.
     *
     * @param  list<string>  $path
     */
    public static function play(int $ply, array $path, int $actedMs, bool $stoodIn = false): self
    {
        return new self(self::PLAY, $ply, $path, null, $stoodIn, $actedMs);
    }

    /** «Sən aç», from the device of the seat with the right. */
    public static function yield(int $ply, int $actedMs): self
    {
        return new self(self::YIELD, $ply, null, null, false, $actedMs);
    }

    /** «Oyuna qayıt», for a seat that was away. */
    public static function back(int $seat, int $ply, int $actedMs): self
    {
        return new self(self::BACK, $ply, null, $seat, false, $actedMs);
    }
}
