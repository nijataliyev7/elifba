<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Play\Data\BoardVoiceData;
use App\Models\User;

/**
 * What a board says besides the prompts (docs/architecture.md §3 "Voice",
 * ADR 015, ADR 016), as the game's owner chose in Settings → Oyun: whether
 * it says each round's `prompt.audio`; the praise and retry clips, or empty
 * lists when praise is off; the greeting («Salam!») and the end phrases
 * («Əla oynadın!», «Əla oynadınız!»), or null when praise is off or the
 * clip is not made; and whether it says a player's name (`names`: the name
 * switch with praise on), whose clips the players' `name_audio` give
 * (`BoardProps`). A phrase without a clip is left out. The URLs are public
 * content files, so a guest board plays them as the teacher board does; a
 * name's clip never is one.
 *
 * @phpstan-type BoardVoiceArray array{prompt: bool, praise: list<string>, retry: list<string>, names: bool, greeting: string|null, finish: string|null, finish_class: string|null}
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
        $phrase = fn (VoiceLineData $line): ?string => $choice->praise ? $this->clips->url($line) : null;

        return [
            'prompt' => $choice->prompt,
            'praise' => $choice->praise ? $urls($this->clips->praise()) : [],
            'retry' => $choice->praise ? $urls($this->clips->retry()) : [],
            'names' => $choice->saysNames(),
            'greeting' => $phrase($this->clips->greeting()),
            'finish' => $phrase($this->clips->finish()),
            'finish_class' => $phrase($this->clips->finishClass()),
        ];
    }
}
