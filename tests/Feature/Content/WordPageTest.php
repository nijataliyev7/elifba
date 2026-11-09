<?php

use App\Domain\Content\Models\CustomWord;
use App\Domain\Content\Models\HiddenWord;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\WordRules;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * The teacher's words page and its routes (routes/words.php,
 * docs/architecture.md §3 "Teacher words"): the page, adding, editing and
 * deleting a teacher word, hiding and showing a built-in word. Another
 * teacher's `{word}` is in tests/Feature/Classroom/TeacherIsolationTest.php
 * too; the rules themselves are in TeacherWordsTest and TeacherWordRulesTest.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->other = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function teacherWord(User $teacher, array $attributes = []): CustomWord
{
    return CustomWord::factory()->for($teacher, 'teacher')->create($attributes + ['pack' => 'fruit', 'text' => 'pomidor']);
}

it('sends a guest to the login page', function (string $method, string $uri): void {
    $this->call($method, $uri, ['pack' => 'fruit', 'text' => 'pomidor', 'word' => 'alma'])
        ->assertRedirect(route('login'));

    expect(CustomWord::query()->count())->toBe(0)
        ->and(HiddenWord::query()->count())->toBe(0);
})->with([
    'GET words' => ['GET', '/words'],
    'POST words' => ['POST', '/words'],
    'POST words/hidden' => ['POST', '/words/hidden'],
    'DELETE words/hidden' => ['DELETE', '/words/hidden'],
]);

it('shows the first pack with its built-in words, syllables joined by the server', function (): void {
    $packs = app(ContentRepository::class)->packs();
    $first = $packs[array_key_first($packs)];

    $this->actingAs($this->teacher)->get(route('words.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('words/index')
            ->where('pack', $first->key)
            ->has('packs', count($packs))
            ->where('packs.0', ['key' => $first->key, 'title' => $first->title->az])
            ->has('builtInWords', count($first->words))
            ->where('builtInWords.0.text', $first->words[0]->text)
            ->where('builtInWords.0.syllables', implode('-', $first->words[0]->syllables))
            ->where('builtInWords.0.hidden', false)
            ->where('customWords', [])
            ->where('limits', ['max_length' => 20, 'max_per_teacher' => 200, 'count' => 0]));
});

it('shows a pack with the teacher\'s own words and hidden words, and nobody else\'s', function (): void {
    $pomidor = teacherWord($this->teacher, ['text' => 'pomidor', 'syllables' => ['pom', 'i', 'dor']]);
    teacherWord($this->teacher, ['text' => 'kələm']);
    teacherWord($this->teacher, ['pack' => 'train', 'text' => 'vaqon']);
    teacherWord($this->other, ['text' => 'badımcan']);
    HiddenWord::query()->forceCreate(['user_id' => $this->teacher->id, 'pack' => 'fruit', 'word_key' => 'word:alma']);
    HiddenWord::query()->forceCreate(['user_id' => $this->other->id, 'pack' => 'fruit', 'word_key' => 'word:armud']);

    $this->actingAs($this->teacher)->get(route('words.index', ['pack' => 'fruit']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pack', 'fruit')
            ->where('customWords', [
                ['id' => $pomidor->id, 'text' => 'pomidor', 'syllables' => 'pom-i-dor', 'override' => 'pom-i-dor', 'played' => true],
                ['id' => CustomWord::query()->where('text', 'kələm')->value('id'), 'text' => 'kələm', 'syllables' => 'kə-ləm', 'override' => null, 'played' => true],
            ])
            ->where('builtInWords.0', ['text' => 'alma', 'syllables' => 'al-ma', 'hidden' => true])
            ->where('builtInWords.1', ['text' => 'armud', 'syllables' => 'ar-mud', 'hidden' => false])
            ->where('limits.count', 3));
});

it('names the packs in the interface language and falls back to the first pack', function (): void {
    $this->teacher->forceFill(['locale' => 'en'])->save();
    $packs = app(ContentRepository::class)->packs();

    $this->actingAs($this->teacher)->get(route('words.index', ['pack' => 'no-such-pack']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pack', array_key_first($packs))
            ->where('packs.0.title', $packs[array_key_first($packs)]->title->en));
});

it('marks a teacher word a later pack version made built-in as not played', function (): void {
    teacherWord($this->teacher, ['text' => 'Alma']);

    $this->actingAs($this->teacher)->get(route('words.index', ['pack' => 'fruit']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('customWords.0.played', false));
});

it('adds a word with a typed syllable split and returns to its pack', function (): void {
    $this->actingAs($this->teacher)->from(route('words.index', ['pack' => 'fruit']))
        ->post(route('words.store'), ['pack' => 'fruit', 'text' => ' Pomidor ', 'syllables' => 'Pom – i-dor'])
        ->assertRedirect(route('words.index', ['pack' => 'fruit']))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => __('words.flash.added')]);

    expect(CustomWord::query()->sole())
        ->user_id->toBe($this->teacher->id)
        ->pack->toBe('fruit')
        ->text->toBe('Pomidor')
        ->syllables->toBe(['Pom', 'i', 'dor']);
});

it('adds a word without a split, or with the server\'s own split, as no override', function (string $typed): void {
    $this->actingAs($this->teacher)
        ->post(route('words.store'), ['pack' => 'fruit', 'text' => 'kələm', 'syllables' => $typed])
        ->assertSessionHasNoErrors();

    expect(CustomWord::query()->sole()->syllables)->toBeNull();
})->with([
    'empty' => [''],
    'blank' => ['  '],
    'the same split' => ['kə-ləm'],
]);

it('refuses an invalid word with the server\'s messages and stores nothing', function (array $input, string $field, string $reason): void {
    $this->actingAs($this->teacher)->from(route('words.index', ['pack' => 'fruit']))
        ->post(route('words.store'), $input + ['pack' => 'fruit'])
        ->assertRedirect(route('words.index', ['pack' => 'fruit']))
        ->assertSessionHasErrors([$field => WordRules::message($reason)]);

    expect(CustomWord::query()->count())->toBe(0);
})->with([
    'two words' => [['text' => 'qırmızı alma'], 'text', 'letters'],
    'a digit' => [['text' => 'alma2'], 'text', 'letters'],
    'casing' => [['text' => 'pOmidor'], 'text', 'casing'],
    'a built-in word' => [['text' => 'Alma'], 'text', 'built_in'],
    'a split that does not join back' => [['text' => 'pomidor', 'syllables' => 'po-mi-dorr'], 'syllables', 'split'],
    'a split with a vowelless part' => [['text' => 'pomidor', 'syllables' => 'p-omi-dor'], 'syllables', 'split'],
]);

it('refuses an invalid word with a translated message in each language', function (string $locale): void {
    $this->teacher->forceFill(['locale' => $locale])->save();

    $this->actingAs($this->teacher)
        ->post(route('words.store'), ['pack' => 'fruit', 'text' => 'qırmızı alma'])
        ->assertSessionHasErrors(['text' => trans('words.errors.letters', [], $locale)]);

    $this->actingAs($this->teacher)
        ->post(route('words.store'), ['pack' => 'fruit', 'text' => ''])
        ->assertSessionHasErrors(['text' => trans('validation.required', ['attribute' => trans('words.attributes.text', [], $locale)], $locale)]);
})->with(['az', 'en']);

it('refuses a word the teacher already has, and an unknown pack', function (): void {
    teacherWord($this->teacher, ['text' => 'pomidor']);

    $this->actingAs($this->teacher)
        ->post(route('words.store'), ['pack' => 'fruit', 'text' => 'Pomidor'])
        ->assertSessionHasErrors(['text' => WordRules::message('taken')]);

    $this->actingAs($this->teacher)
        ->post(route('words.store'), ['pack' => 'no-such-pack', 'text' => 'kələm'])
        ->assertSessionHasErrors('pack');

    expect(CustomWord::query()->count())->toBe(1);
});

it('refuses a word over the teacher\'s cap', function (): void {
    config(['elifba.custom_words.max_per_teacher' => 1]);
    teacherWord($this->teacher);

    $this->actingAs($this->teacher)
        ->post(route('words.store'), ['pack' => 'fruit', 'text' => 'kələm'])
        ->assertSessionHasErrors(['text' => WordRules::message('too_many', ['max' => 1])]);

    expect(CustomWord::query()->count())->toBe(1);
});

it('edits a word, its split and its pack', function (): void {
    $word = teacherWord($this->teacher, ['text' => 'pomidor']);

    $this->actingAs($this->teacher)->from(route('words.index', ['pack' => 'fruit']))
        ->patch(route('words.update', $word), ['pack' => 'train', 'text' => 'Pomidor', 'syllables' => 'Pom-i-dor'])
        ->assertRedirect(route('words.index', ['pack' => 'train']))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => __('words.flash.updated')]);

    expect($word->fresh())
        ->pack->toBe('train')
        ->text->toBe('Pomidor')
        ->syllables->toBe(['Pom', 'i', 'dor']);

    // An empty split field drops the override.
    $this->actingAs($this->teacher)
        ->patch(route('words.update', $word), ['pack' => 'train', 'text' => 'Pomidor', 'syllables' => ''])
        ->assertSessionHasNoErrors();

    expect($word->fresh()->syllables)->toBeNull();
});

it('refuses an invalid edit and keeps the word', function (): void {
    $word = teacherWord($this->teacher, ['text' => 'pomidor']);
    teacherWord($this->teacher, ['text' => 'kələm']);
    $before = $word->fresh()?->toArray();

    $this->actingAs($this->teacher)->from(route('words.index', ['pack' => 'fruit']))
        ->patch(route('words.update', $word), ['pack' => 'fruit', 'text' => 'Kələm'])
        ->assertRedirect(route('words.index', ['pack' => 'fruit']))
        ->assertSessionHasErrors(['text' => WordRules::message('taken')]);

    $this->actingAs($this->teacher)
        ->patch(route('words.update', $word), ['pack' => 'fruit', 'text' => 'pomidor', 'syllables' => 'pom-id-or-x'])
        ->assertSessionHasErrors(['syllables' => WordRules::message('split')]);

    expect($word->fresh()?->toArray())->toBe($before);
});

it('deletes a word and returns to its pack', function (): void {
    $word = teacherWord($this->teacher, ['pack' => 'train']);

    $this->actingAs($this->teacher)
        ->delete(route('words.destroy', $word))
        ->assertRedirect(route('words.index', ['pack' => 'train']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => __('words.flash.deleted')]);

    expect(CustomWord::query()->count())->toBe(0);
});

it('refuses another teacher\'s word: 404, nothing changes, the word never shows', function (): void {
    $word = teacherWord($this->other, ['text' => 'Nərgiz']);
    $before = $word->fresh()?->toArray();

    $update = $this->actingAs($this->teacher)
        ->patch(route('words.update', $word), ['pack' => 'fruit', 'text' => 'kələm']);
    $delete = $this->actingAs($this->teacher)->delete(route('words.destroy', $word));

    $update->assertNotFound();
    $delete->assertNotFound();

    expect($update->getContent())->not->toContain('Nərgiz')
        ->and($word->fresh()?->toArray())->toBe($before);

    $this->actingAs($this->teacher)->get(route('words.index', ['pack' => 'fruit']))
        ->assertDontSee('Nərgiz');
});

it('hides a built-in word with the word in the body and shows it again', function (): void {
    $this->actingAs($this->teacher)->from(route('words.index', ['pack' => 'fruit']))
        ->post(route('words.hidden.store'), ['pack' => 'fruit', 'word' => 'Alma'])
        ->assertRedirect(route('words.index', ['pack' => 'fruit']))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => __('words.flash.hidden')]);

    expect(HiddenWord::query()->sole())
        ->user_id->toBe($this->teacher->id)
        ->pack->toBe('fruit')
        ->word_key->toBe('word:alma');

    // Hiding again keeps one row; the other teacher is not affected.
    $this->actingAs($this->teacher)->post(route('words.hidden.store'), ['pack' => 'fruit', 'word' => 'alma']);
    $this->actingAs($this->other)->delete(route('words.hidden.destroy'), ['pack' => 'fruit', 'word' => 'alma']);

    expect(HiddenWord::query()->count())->toBe(1);

    $this->actingAs($this->teacher)
        ->delete(route('words.hidden.destroy'), ['pack' => 'fruit', 'word' => 'alma'])
        ->assertRedirect(route('words.index', ['pack' => 'fruit']))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => __('words.flash.shown')]);

    expect(HiddenWord::query()->count())->toBe(0);
});

it('refuses to hide a word the pack does not have, or an unknown pack', function (string $method): void {
    $this->actingAs($this->teacher)->from(route('words.index', ['pack' => 'fruit']))
        ->call($method, route($method === 'POST' ? 'words.hidden.store' : 'words.hidden.destroy'), ['pack' => 'fruit', 'word' => 'pomidor'])
        ->assertRedirect(route('words.index', ['pack' => 'fruit']))
        ->assertSessionHasErrors(['word' => WordRules::message('not_in_pack')]);

    $this->actingAs($this->teacher)
        ->call($method, route($method === 'POST' ? 'words.hidden.store' : 'words.hidden.destroy'), ['pack' => 'no-such-pack', 'word' => 'alma'])
        ->assertSessionHasErrors('pack');

    expect(HiddenWord::query()->count())->toBe(0);
})->with(['POST', 'DELETE']);

it('never puts a word in a route\'s URL', function (): void {
    $word = teacherWord($this->teacher, ['text' => 'Aysel']);

    foreach (['words.update', 'words.destroy'] as $name) {
        expect(route($name, $word))->not->toContain('Aysel')->toEndWith('/words/'.$word->id);
    }

    expect(route('words.hidden.store'))->toEndWith('/words/hidden')
        ->and(route('words.hidden.destroy'))->toEndWith('/words/hidden');
});
