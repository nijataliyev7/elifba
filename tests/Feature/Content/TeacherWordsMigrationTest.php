<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * The migration that makes teacher words teacher-wide (ADR 009): copies of
 * one word a teacher typed into several packs become one row, the earliest,
 * with its override; other teachers' words and distinct words stay.
 */

it('keeps one row per teacher and word, the earliest, and drops the pack', function (): void {
    $migration = require database_path('migrations/2026_11_16_000001_make_custom_words_teacher_wide.php');
    $teacher = User::factory()->create();
    $other = User::factory()->create();

    $migration->down();

    $row = static function (User $user, string $pack, string $text, string $at, ?array $syllables = null): string {
        $id = (string) Str::ulid();
        DB::table('custom_words')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'pack' => $pack,
            'text' => $text,
            'word_key' => 'word:'.mb_strtolower($text),
            'syllables' => $syllables === null ? null : json_encode($syllables),
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $id;
    };

    $later = $row($teacher, 'fruit', 'pomidor', '2026-11-12 10:00:00');
    $first = $row($teacher, 'train', 'pomidor', '2026-11-11 10:00:00', ['pom', 'i', 'dor']);
    $kelem = $row($teacher, 'fruit', 'kələm', '2026-11-12 11:00:00');
    $theirs = $row($other, 'fruit', 'pomidor', '2026-11-13 10:00:00');

    $migration->up();

    expect(Schema::hasColumn('custom_words', 'pack'))->toBeFalse()
        ->and(DB::table('custom_words')->orderBy('id')->pluck('id')->all())->toEqualCanonicalizing([$first, $kelem, $theirs])
        ->and(DB::table('custom_words')->where('id', $later)->exists())->toBeFalse()
        ->and(json_decode((string) DB::table('custom_words')->where('id', $first)->value('syllables'), true))->toBe(['pom', 'i', 'dor']);
});
