<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SetupMode;
use App\Domain\Play\Models\SetupChoice;

/**
 * What a class or a child chose once on «Yeni oyun», kept for the next
 * time (the owner, 2026-11-13; docs/architecture.md §2 "Remembered
 * setup"): one `setup_choices` row each. Two actions write it — the mode
 * when it is tapped (`RememberSetupMode`), the rest of a game's setup when
 * the game starts (`StartGame`, inside its transaction) — and the catalog
 * reads it through `recall()`, which drops what no longer applies.
 *
 * @phpstan-type RememberedArray array{mode: string, levels: array<string, string>, letterCases: array<string, string>, letters: list<string>|null, ownWords: bool|null}
 */
final class SetupMemory
{
    /**
     * The remembered setup as the catalog receives it: the mode (else
     * «Normal» for a class and «Asan» for a child); per unit the level key
     * still offered for that unit and the letter case of a reading unit;
     * the letters still learned, null when fewer than two are left; and
     * «Yalnız mənim sözlərim», null where never chosen.
     *
     * @return RememberedArray
     */
    public static function recall(GamePlayers $players): array
    {
        $row = self::find($players);
        $levels = app(ContentRepository::class)->levels();
        $learned = $players->learned();
        $letters = $row?->letters === null ? null : array_values(array_intersect($learned, $row->letters));

        return [
            'mode' => ($row->mode ?? SetupMode::first($players->isHome()))->value,
            'levels' => array_filter(
                $row->levels ?? [],
                static fn (string $key, string $unit): bool => isset($levels[$key]) && $levels[$key]->unit->value === $unit,
                ARRAY_FILTER_USE_BOTH,
            ),
            'letterCases' => array_filter(
                $row->letter_cases ?? [],
                static fn (string $case, string $unit): bool => LetterCase::tryFrom($case) !== null && Unit::tryFrom($unit)?->reads() === true,
                ARRAY_FILTER_USE_BOTH,
            ),
            'letters' => $letters === null || count($letters) < 2 ? null : $letters,
            'ownWords' => $row?->own_words,
        ];
    }

    /**
     * Keeps the mode just tapped. The caller holds a transaction.
     */
    public static function rememberMode(GamePlayers $players, SetupMode $mode): void
    {
        $row = self::lock($players);
        $row->mode = $mode;
        $row->save();
    }

    /**
     * Keeps what a game was started with, inside `StartGame`'s transaction:
     * the mode of its mechanic, its level under its unit, its letter case
     * under its unit (a reading unit), its letters (the letter level) and
     * its `own_words` (only where `Playable::takesOwnWords()` and the owner's
     * own words could start, so it was a choice).
     */
    public static function remember(GamePlayers $players, GameSetupData $setup): void
    {
        $level = app(ContentRepository::class)->level($setup->level);
        $unit = $level->unit->value;
        $row = self::lock($players);

        $row->mode = SetupMode::of($setup->mechanic);
        $row->levels = [...($row->levels ?? []), $unit => $level->key];

        if ($level->unit->reads()) {
            $row->letter_cases = [...($row->letter_cases ?? []), $unit => $setup->letter_case->value];
        }

        if ($level->unit === Unit::Letter) {
            $row->letters = $setup->letters;
        }

        if (Playable::takesOwnWords($setup->mechanic, $level) && ($setup->own_words || self::ownWordsCouldStart($players, $setup, $level))) {
            $row->own_words = $setup->own_words;
        }

        $row->save();
    }

    /**
     * Whether «Yalnız mənim sözlərim» was there to choose: a game of the
     * owner's own words could start. Without it, «Hamısı» was no choice and
     * is not kept, so the owner's first words still open with it on.
     */
    private static function ownWordsCouldStart(GamePlayers $players, GameSetupData $setup, LevelData $level): bool
    {
        $own = app(TeacherPacks::class)->pack($players->owner, $setup->pack, own: true);

        return Playable::reason($setup->mechanic, $own, $level, $players->learned(), own: true) === null;
    }

    private static function find(GamePlayers $players): ?SetupChoice
    {
        return SetupChoice::query()->where($players->columns())->first();
    }

    /**
     * The row of the class or the child, made when missing and locked for
     * the rest of the caller's transaction.
     */
    private static function lock(GamePlayers $players): SetupChoice
    {
        $id = SetupChoice::query()
            ->firstOrCreate($players->columns(), ['mode' => SetupMode::first($players->isHome())])
            ->id;

        return SetupChoice::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
