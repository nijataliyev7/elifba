<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomPhase;
use InvalidArgumentException;

/**
 * The current round of a live room game as one viewer sees it
 * (docs/architecture.md §12 "The state", `RoomRound`, ADR 021 decision 6;
 * zod `RoomRound`, split by phase). What is withheld is the rule, and the
 * constructor refuses a round that breaks it, so neither the server nor a
 * fixture can carry a key in the wrong phase:
 *
 * - `countdown` (before round 0 opens): the number and the moments, nothing
 *   of the content;
 * - `study`: the material to remember, and nothing of the question;
 * - `answer`: the question, never the material again; whether the viewer
 *   has answered, and how many present players have;
 * - `reveal` (once the round is closed): the question with the solution,
 *   the viewer's own answer, every player's result, and when the round
 *   closed (`closes_ms`) and the next one opens (`next_ms`).
 *
 * The moments are Unix milliseconds of the server's clock: `opens_ms`,
 * `answer_from_ms` and the bell are known from the start; the close and
 * the next opening only once the round has closed, since an early close
 * may come before the bell.
 *
 * From ADR 037 (decision 14) `letters`: the letters of an anagram's word
 * the viewer bought, their orders and the next one's price — only in the
 * phase `answer`, only in a present player's own state of a round that
 * sells letters (`RoomLetters`), null for everyone else and the screen.
 *
 * @phpstan-import-type RoomStudyArray from RoomStudyData
 * @phpstan-import-type RoomAskArray from RoomAskData
 * @phpstan-import-type RoomRevealArray from RoomRevealData
 * @phpstan-import-type RoomLettersArray from RoomLettersData
 *
 * @phpstan-type RoomRoundArray array{id: string, index: int, total: int, phase: string, opens_ms: int, answer_from_ms: int, bell_ms: int, closes_ms: int|null, next_ms: int|null, study: RoomStudyArray|null, ask: RoomAskArray|null, answered: bool, answered_count: int, present_count: int, reveal: RoomRevealArray|null, letters: RoomLettersArray|null}
 */
final readonly class RoomRoundData
{
    public function __construct(
        public string $id,
        public int $index,
        public int $total,
        public RoomPhase $phase,
        public int $opens_ms,
        public int $answer_from_ms,
        public int $bell_ms,
        public ?int $closes_ms,
        public ?int $next_ms,
        public ?RoomStudyData $study,
        public ?RoomAskData $ask,
        public bool $answered,
        public int $answered_count,
        public int $present_count,
        public ?RoomRevealData $reveal,
        public ?RoomLettersData $letters = null,
    ) {
        $revealed = $phase === RoomPhase::Reveal;
        $asked = $revealed || $phase === RoomPhase::Answer;

        $wrong = match (true) {
            $phase === RoomPhase::Over => 'An over game has no round.',
            $index < 0 || $index >= $total => 'The round is outside its game.',
            $phase === RoomPhase::Countdown && $index !== 0 => 'Only round 0 has a countdown.',
            ($study !== null) !== ($phase === RoomPhase::Study) => 'The material is served only while it is studied.',
            ($ask !== null) !== $asked => 'The question is served from the answer phase on.',
            ($reveal !== null) !== $revealed => 'The solution and the results are served only in the reveal.',
            ($closes_ms !== null) !== $revealed || ($next_ms !== null) !== $revealed => 'The close is known once the round has closed.',
            ! $asked && ($answered || $answered_count !== 0) => 'Nobody answers before the question.',
            $answered_count < 0 || $answered_count > $present_count => 'More answers than present players.',
            $letters !== null && $phase !== RoomPhase::Answer => 'Bought letters are served only while the round takes answers.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    /**
     * @param  RoomRoundArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            index: $data['index'],
            total: $data['total'],
            phase: RoomPhase::from($data['phase']),
            opens_ms: $data['opens_ms'],
            answer_from_ms: $data['answer_from_ms'],
            bell_ms: $data['bell_ms'],
            closes_ms: $data['closes_ms'],
            next_ms: $data['next_ms'],
            study: $data['study'] === null ? null : RoomStudyData::fromArray($data['study']),
            ask: $data['ask'] === null ? null : RoomAskData::fromArray($data['ask']),
            answered: $data['answered'],
            answered_count: $data['answered_count'],
            present_count: $data['present_count'],
            reveal: $data['reveal'] === null ? null : RoomRevealData::fromArray($data['reveal']),
            letters: $data['letters'] === null ? null : RoomLettersData::fromArray($data['letters']),
        );
    }

    /**
     * @return RoomRoundArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'index' => $this->index,
            'total' => $this->total,
            'phase' => $this->phase->value,
            'opens_ms' => $this->opens_ms,
            'answer_from_ms' => $this->answer_from_ms,
            'bell_ms' => $this->bell_ms,
            'closes_ms' => $this->closes_ms,
            'next_ms' => $this->next_ms,
            'study' => $this->study?->toArray(),
            'ask' => $this->ask?->toArray(),
            'answered' => $this->answered,
            'answered_count' => $this->answered_count,
            'present_count' => $this->present_count,
            'reveal' => $this->reveal?->toArray(),
            'letters' => $this->letters?->toArray(),
        ];
    }
}
