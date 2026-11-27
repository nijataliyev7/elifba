<?php

namespace App\Domain\Content\Enums;

/**
 * The sounds of a room game (docs/architecture.md §12 "Room content", ADR
 * 025 "Sound", ADR 028; a board game's four, ADR 034 decision 31; a domino
 * game's five, ADR 038 decision 90), one
 * file each in `content/rooms/sounds.json`. The page decides when one
 * plays (`components/rooms/sound-cues.ts`, which names the same values);
 * nothing audible before the reveal tells right from wrong: `Clock` is the
 * same for everyone and `Sent` one sound for every answer, and `Right` and
 * `Wrong` play only from the reveal, on the viewer's own phone. Separate
 * from the children's `Sound` and `content/sounds.json`.
 */
enum RoomSound: string
{
    /** The screen: the countdown before round 0, one file that ends at `opens_ms`. */
    case Countdown = 'countdown';

    /** The screen: round 0 opens, in place of its study or its question. */
    case Go = 'go';

    /** The screen: a later round's study opens. */
    case Study = 'study';

    /** The screen: a question opens. */
    case Question = 'question';

    /** The screen: the last 5 s of an answer, one file that ends at `bell_ms`. */
    case Clock = 'clock';

    /** The screen: the round closes and the reveal opens, whatever anyone answered. */
    case Close = 'close';

    /** The screen: the reveal's ranking comes in. */
    case Ranking = 'ranking';

    /** The screen: the final's third or second place opens. */
    case Place = 'place';

    /** The screen: the final's roll, ending where the first place opens. */
    case Roll = 'roll';

    /** The screen: the fanfare when the final's first place opens. */
    case Fanfare = 'fanfare';

    /** The screen, between games: a player joined. */
    case Join = 'join';

    /** The screen, in a board game: a move the page saw arrive, a piece put down. */
    case Move = 'move';

    /** The screen, in a board game: a move that took one piece or more, in place of `Move`. */
    case Capture = 'capture';

    /** The screen, in a board game: that move crowned a man, after its `Move` or `Capture`. */
    case Crown = 'crown';

    /** The screen, in a domino game: a play the page saw arrive, a double's too, as its tile settles. */
    case Tile = 'tile';

    /** The screen, in a domino game: a seat drew from the boneyard, as its step of `last.then` is drawn. */
    case Draw = 'draw';

    /** The screen, in a domino game: a seat passed. */
    case Knock = 'knock';

    /** The screen, in a domino game: a hand ended and its sheet rises. */
    case Hand = 'hand';

    /** A phone: the viewer's own tap, the same for every item. */
    case Tap = 'tap';

    /** A phone: a tap taken back («Geri», a cell tapped again). */
    case Undo = 'undo';

    /** A phone: the viewer's answer is in, the same for every answer. */
    case Sent = 'sent';

    /** A phone, from the reveal: the viewer's answer was right. */
    case Right = 'right';

    /** A phone, from the reveal: the viewer's answer was wrong. */
    case Wrong = 'wrong';

    /** A phone, in a board game: a draw offer arrives at the viewer. */
    case Offer = 'offer';

    /** A phone, in a domino game: the viewer's own turn begins — on the seat to move's phone, with a screen too. */
    case Turn = 'turn';

    public function speaker(): RoomSpeaker
    {
        return match ($this) {
            self::Tap, self::Undo, self::Sent, self::Right, self::Wrong, self::Offer, self::Turn => RoomSpeaker::Phone,
            default => RoomSpeaker::Screen,
        };
    }

    /**
     * The sounds one speaker plays, in the enum's order.
     *
     * @return list<self>
     */
    public static function of(RoomSpeaker $speaker): array
    {
        return array_values(array_filter(self::cases(), static fn (self $sound): bool => $sound->speaker() === $speaker));
    }
}
