<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Play\Data\BoardVoiceData;
use App\Models\User;

/**
 * What a board says besides the prompts (docs/architecture.md §3 "Voice",
 * ADR 015), as the game's owner chose in Settings → Oyun: whether it says
 * each round's `prompt.audio`, and the praise and retry clips, or empty
 * lists when praise is off. A phrase without a clip is left out. The URLs
 * are public content files, so a guest board plays them as the teacher
 * board does.
 *
 * @phpstan-type BoardVoiceArray array{prompt: bool, praise: list<string>, retry: list<string>}
 */
final readonly class BoardVoice
{
    public function __construct(private VoiceClips $clips) {}

    /**
     * @return BoardVoiceArray
     */
    public function of(User $owner): array
    {
        $choice = BoardVoiceData::fromStored($owner->board_voice);
        $urls = fn (array $lines): array => array_values(array_filter(array_map(
            fn (VoiceLineData $line): ?string => $this->clips->url($line),
            $lines,
        )));

        return [
            'prompt' => $choice->prompt,
            'praise' => $choice->praise ? $urls($this->clips->praise()) : [],
            'retry' => $choice->praise ? $urls([$this->clips->retry()]) : [],
        ];
    }
}
