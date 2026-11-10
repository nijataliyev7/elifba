<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\ItemStyle;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\SlotStyle;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Play\Support\Mechanics\BuildSentenceRoundBuilder;
use App\Domain\Play\Support\Mechanics\BuildSyllableRoundBuilder;
use App\Domain\Play\Support\Mechanics\BuildWordRoundBuilder;
use App\Domain\Play\Support\Mechanics\FindRoundBuilder;
use App\Domain\Play\Support\Mechanics\PopRoundBuilder;
use App\Domain\Play\Support\Mechanics\RoundBuilder;
use App\Models\User;

/**
 * The one server-side list of what the board can draw
 * (docs/architecture.md §2): the mechanics with a round builder and a
 * scene, and the item styles, slot styles and effects implemented in
 * `resources/js/game/styles/` (`registry.ts` maps the same keys to their
 * code). `GameSetupData` and the setup page use it, so a game the board
 * cannot draw never starts. A8.1 lists the build mechanics with their
 * builders, A8.2 draws every item style and `sparkle`, and A8.3 the slot
 * styles `wagon` and `frame`: every mechanic plays in every pack. `pop`
 * reuses the find scene and has no slots.
 *
 * A pack is playable with a mechanic only when the board draws its item
 * style and its effect (and its slot style, for a build mechanic). Rounds
 * take the pack's own effect, so the symbols and cities packs, whose effect
 * is `sparkle`, are never burst (§8).
 */
final class Playable
{
    /**
     * @return list<Mechanic>
     */
    public static function mechanics(): array
    {
        return [Mechanic::Find, Mechanic::Pop, Mechanic::BuildSyllable, Mechanic::BuildWord, Mechanic::BuildSentence];
    }

    /**
     * @return list<ItemStyle>
     */
    public static function itemStyles(): array
    {
        return [ItemStyle::Balloon, ItemStyle::Wagon, ItemStyle::Card, ItemStyle::Bubble, ItemStyle::Animal, ItemStyle::Fruit];
    }

    /**
     * @return list<SlotStyle>
     */
    public static function slotStyles(): array
    {
        return [SlotStyle::Wagon, SlotStyle::Frame];
    }

    /**
     * @return list<Effect>
     */
    public static function effects(): array
    {
        return [Effect::Burst, Effect::Sparkle];
    }

    /**
     * Whether the board can draw this mechanic in this pack's look.
     */
    public static function draws(Mechanic $mechanic, PackData $pack): bool
    {
        $style = $pack->style;

        return in_array($mechanic, self::mechanics(), true)
            && in_array($style->item, self::itemStyles(), true)
            && in_array($style->effect, self::effects(), true)
            && (! $mechanic->builds() || in_array($style->slot, self::slotStyles(), true));
    }

    /**
     * The round builder of a mechanic; whether the board can draw it in a
     * pack is `draws()`.
     */
    public static function builder(Mechanic $mechanic): RoundBuilder
    {
        return match ($mechanic) {
            Mechanic::Find => app(FindRoundBuilder::class),
            Mechanic::Pop => app(PopRoundBuilder::class),
            Mechanic::BuildSyllable => app(BuildSyllableRoundBuilder::class),
            Mechanic::BuildWord => app(BuildWordRoundBuilder::class),
            Mechanic::BuildSentence => app(BuildSentenceRoundBuilder::class),
        };
    }

    /**
     * Why this game cannot start for a class with these learned letters, as
     * a message for the teacher; null when it can. `$pack` is the pack as the
     * class's teacher plays it (`TeacherPacks`), so words the teacher hid can
     * leave a level short (`too_few_*`). The setup page and
     * `GameSetupData::rules()` ask the same question.
     *
     * @param  list<string>  $learned
     * @param  list<string>|null  $letters  the teacher's choice for a letter level
     */
    public static function reason(Mechanic $mechanic, PackData $pack, LevelData $level, array $learned, ?array $letters = null): ?string
    {
        return match (true) {
            ! $level->allows($mechanic) => self::message('level_mechanic'),
            $letters !== null && $level->unit !== Unit::Letter => self::message('letters_level'),
            ! self::draws($mechanic, $pack) => self::message('not_playable'),
            default => self::builder($mechanic)->shortage($pack, $level, $learned, $letters),
        };
    }

    /**
     * Every game the class could set up — each mechanic its level allows,
     * in every pack as the class's teacher plays it — with the reason it
     * cannot start, or null: what the setup page offers.
     *
     * @param  list<string>  $learned
     * @return list<array{mechanic: Mechanic, pack: PackData, level: LevelData, reason: string|null}>
     */
    public static function options(User $teacher, array $learned): array
    {
        $content = app(ContentRepository::class);
        $packs = app(TeacherPacks::class)->packs($teacher);
        $options = [];

        foreach ($content->levels() as $level) {
            foreach ($level->mechanics as $mechanic) {
                foreach ($packs as $pack) {
                    $options[] = [
                        'mechanic' => $mechanic,
                        'pack' => $pack,
                        'level' => $level,
                        'reason' => self::reason($mechanic, $pack, $level, $learned),
                    ];
                }
            }
        }

        return $options;
    }

    /**
     * A reason from `games.setup.errors.*` in the interface language.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function message(string $reason, array $replace = []): string
    {
        $key = "games.setup.errors.{$reason}";
        $message = __($key, $replace);

        return is_string($message) ? $message : $key;
    }
}
