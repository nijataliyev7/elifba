<?php

namespace App\Domain\Play\Models;

use App\Domain\Play\Enums\SetupMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a class or a child last chose on «Yeni oyun» (docs/architecture.md
 * §1, §2 "Remembered setup"): at most one row each, written only by
 * `Play\Support\SetupMemory` (from `RememberSetupMode` and `StartGame`) and
 * read through `SetupMemory::recall()`, which drops what no longer applies.
 * It holds no name and nothing about a child (ADR 013); it is reached
 * through its class or child, whose scoped binding guards it, and goes
 * with it.
 *
 * @property int $id never in a URL
 * @property string|null $school_class_id
 * @property string|null $child_id
 * @property SetupMode $mode
 * @property array<string, string>|null $levels unit → the level key last started
 * @property array<string, string>|null $letter_cases unit → `upper` | `lower` last started
 * @property list<string>|null $letters the letters last asked for at the letter level; null = every learned one
 * @property bool|null $own_words the last «Yalnız mənim sözlərim» where it applied; null = never chosen
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['school_class_id', 'child_id', 'mode', 'levels', 'letter_cases', 'letters', 'own_words'])]
class SetupChoice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => SetupMode::class,
            'levels' => 'array',
            'letter_cases' => 'array',
            'letters' => 'array',
            'own_words' => 'boolean',
        ];
    }
}
