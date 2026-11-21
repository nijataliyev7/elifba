<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Models\RoomAnswer;

/**
 * The viewer's own answer in a round's reveal (docs/architecture.md §12
 * "The state", `reveal.mine`): the items picked in the order tapped, right
 * or wrong, the points and the time the server measured. Served only in
 * the phase `reveal`. From ADR 037 (decision 19) also how many letters of
 * an anagram's word the viewer bought in the round — 0 without any — so
 * the points explain themselves on the buyer's phone.
 *
 * @phpstan-type RoomMineArray array{picked: list<string>, correct: bool, points: int, elapsed_ms: int, letters: int}
 */
final readonly class RoomMineData
{
    /**
     * @param  list<string>  $picked
     */
    public function __construct(
        public array $picked,
        public bool $correct,
        public int $points,
        public int $elapsed_ms,
        public int $letters = 0,
    ) {}

    /**
     * @param  int  $letters  the letters the viewer bought in the round (`RoomLetters::count()`)
     */
    public static function of(RoomAnswer $answer, int $letters): self
    {
        return new self($answer->picked, $answer->correct, $answer->points, $answer->elapsed_ms, $letters);
    }

    /**
     * @param  RoomMineArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['picked'], $data['correct'], $data['points'], $data['elapsed_ms'], $data['letters']);
    }

    /**
     * @return RoomMineArray
     */
    public function toArray(): array
    {
        return [
            'picked' => $this->picked,
            'correct' => $this->correct,
            'points' => $this->points,
            'elapsed_ms' => $this->elapsed_ms,
            'letters' => $this->letters,
        ];
    }
}
