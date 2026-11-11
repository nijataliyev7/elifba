<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Play\Support\BoardProps;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;
use Tests\Support\Voice;

/*
 * Settings → Oyun, the voice (docs/architecture.md §3 "Voice", ADR 015):
 * «Tapşırığı səslə de» and «Tərif səsi», both on for a new account, and
 * what the account's boards get from them — whether prompts are said, and
 * the praise and retry clips, or none.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
});

it('says the prompt and the praise for a new account', function (): void {
    expect($this->teacher->refresh()->board_voice)->toBeNull();

    $this->actingAs($this->teacher)->get(route('play-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/play')
            ->where('voice', ['prompt' => true, 'praise' => true]));
});

it('turns each part off and on again', function (mixed $off, mixed $on): void {
    $this->actingAs($this->teacher)->put(route('play-settings.voice.update'), ['prompt' => $off, 'praise' => $on])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('play-settings.edit'));

    expect($this->teacher->refresh()->board_voice)->toBe(['prompt' => false, 'praise' => true]);

    $this->get(route('play-settings.edit'))->assertInertia(fn (Assert $page) => $page->where('voice', ['prompt' => false, 'praise' => true]));

    $this->put(route('play-settings.voice.update'), ['prompt' => $on, 'praise' => $off])->assertSessionHasNoErrors();

    expect($this->teacher->refresh()->board_voice)->toBe(['prompt' => true, 'praise' => false]);

    // Both on again is the default: nothing stored.
    $this->put(route('play-settings.voice.update'), ['prompt' => $on, 'praise' => $on])->assertSessionHasNoErrors();

    expect($this->teacher->refresh()->board_voice)->toBeNull()
        ->and($this->teacher->phone_remote)->toBeTrue()
        ->and($this->teacher->board_sounds)->toBeNull();
})->with([
    'form fields' => ['0', '1'],
    'JSON booleans' => [false, true],
]);

it('refuses anything but two booleans and changes nothing', function (array $body, string $field): void {
    $this->teacher->forceFill(['board_voice' => ['prompt' => false, 'praise' => true]])->save();

    $this->actingAs($this->teacher)->from(route('play-settings.edit'))
        ->put(route('play-settings.voice.update'), $body)
        ->assertRedirect(route('play-settings.edit'))
        ->assertSessionHasErrors($field);

    expect($this->teacher->refresh()->board_voice)->toBe(['prompt' => false, 'praise' => true]);
})->with([
    'no prompt' => [['praise' => '1'], 'prompt'],
    'no praise' => [['prompt' => '1'], 'praise'],
    'a word' => [['prompt' => 'on', 'praise' => '1'], 'prompt'],
]);

it('lets a parent choose for the home board, and sends a guest to log in', function (): void {
    $parent = User::factory()->parent()->create();

    $this->actingAs($parent)->put(route('play-settings.voice.update'), ['prompt' => '0', 'praise' => '1'])->assertSessionHasNoErrors();

    expect($parent->refresh()->board_voice)->toBe(['prompt' => false, 'praise' => true]);

    auth()->logout();
    $this->put(route('play-settings.voice.update'), ['prompt' => '0', 'praise' => '0'])->assertRedirect(route('login'));
});

it('gives the board the praise and retry clips, and whether it says prompts', function (): void {
    $clips = Voice::made($this->app);
    $praise = array_map($clips->url(...), $clips->praise());
    $retry = $clips->url($clips->retry());

    $this->actingAs($this->teacher)->get(route('games.board', Games::start($this->class)))
        ->assertInertia(fn (Assert $page) => $page
            ->where('voice', ['prompt' => true, 'praise' => $praise, 'retry' => [$retry]]));

    expect($praise)->toHaveCount(4)->each->toStartWith(url('/content/voice/'));

    $this->teacher->forceFill(['board_voice' => ['prompt' => false, 'praise' => false]])->save();

    $this->get(route('games.board', Games::start($this->class)))
        ->assertInertia(fn (Assert $page) => $page
            ->where('voice', ['prompt' => false, 'praise' => [], 'retry' => []]));
});

it('leaves out a phrase whose clip is not made', function (): void {
    Voice::made($this->app, static fn (VoiceLineData $line): bool => $line->phrase === 'Afərin!');
    $clips = app(VoiceClips::class);
    $afarin = array_values(array_filter($clips->praise(), static fn (VoiceLineData $line): bool => $line->phrase === 'Afərin!'))[0];

    $this->actingAs($this->teacher)->get(route('games.board', Games::start($this->class)))
        ->assertInertia(fn (Assert $page) => $page
            ->where('voice', ['prompt' => true, 'praise' => [$clips->url($afarin)], 'retry' => []]));
});

it('follows the game\'s owner on a guest board too', function (): void {
    Voice::made($this->app);
    $this->teacher->forceFill(['board_voice' => ['prompt' => false, 'praise' => true]])->save();
    $game = Games::start($this->class);

    expect(BoardProps::of($game)['voice'])
        ->toMatchArray(['prompt' => false])
        ->and(BoardProps::of($game)['voice']['praise'])->toHaveCount(4);
});
