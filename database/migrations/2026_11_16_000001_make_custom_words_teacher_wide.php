<?php

use App\Domain\Content\Support\ContentRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A teacher word belongs to the teacher and plays in every theme pack
 * (docs/architecture.md §1, §3 "Teacher words", ADR 009). Copies of one word
 * a teacher typed into several packs become one row: the earliest
 * (`created_at`, then `id`), with its syllable override. Started games keep
 * their round snapshots. Words are compared by `word_key` only, never
 * logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seen = [];
        $later = [];
        $rows = DB::table('custom_words')
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'word_key']);

        foreach ($rows as $row) {
            $key = $row->user_id.'|'.$row->word_key;

            if (isset($seen[$key])) {
                $later[] = $row->id;
            } else {
                $seen[$key] = true;
            }
        }

        foreach (array_chunk($later, 500) as $ids) {
            DB::table('custom_words')->whereIn('id', $ids)->delete();
        }

        Schema::table('custom_words', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'pack', 'word_key']);
        });

        Schema::table('custom_words', function (Blueprint $table) {
            $table->dropColumn('pack');
            $table->unique(['user_id', 'word_key']);
        });
    }

    /**
     * The merged copies are not restored; every word comes back in the
     * first pack by key, which the teacher can change.
     */
    public function down(): void
    {
        $first = (string) array_key_first(app(ContentRepository::class)->packs());

        Schema::table('custom_words', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'word_key']);
            $table->string('pack')->default('');
        });

        DB::table('custom_words')->update(['pack' => $first]);

        Schema::table('custom_words', function (Blueprint $table) {
            $table->unique(['user_id', 'pack', 'word_key']);
        });
    }
};
