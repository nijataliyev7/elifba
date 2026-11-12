<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\NameVoices;
use App\Domain\Family\Models\Child;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;
use Tests\Support\GuestBoards;
use Tests\Support\NameClips;

/*
 * A player's first name in the board's voice (docs/architecture.md §3
 * "Voice", ADR 016 decision 8): a board gets the clip only through its game,
 * `GET /games/{game}/players/{player}/voice`, by ids — never a public file,
 * never a name or a hash in a URL — for one of the game's players whose name
 * the list says, while the owner has praise and names on. The clips here
 * are fakes over a fixture list (Tests\Support\NameClips); the rest of the
 * content is the real one.
 */

beforeEach(function (): void {
    $this->voices = NameClips::fake(['Aysel', 'Əli', 'Nihat'], ['Zəhra']);
    $this->owner = User::factory()->teacherAndParent()->create();
    $this->class = SchoolClass::factory()->for($this->owner, 'teacher')->create();
    $this->aysel = Student::factory()->for($this->class)->create(['display_name' => 'Aysel M.', 'position' => 1]);
    $this->ali = Student::factory()->for($this->class)->create(['display_name' => 'Əli', 'position' => 2]);
    $this->zehra = Student::factory()->for($this->class)->create(['display_name' => 'Zəhra', 'position' => 3]);
    $this->ramin = Student::factory()->for($this->class)->create(['display_name' => 'Ramin', 'position' => 4]);
    $this->game = Games::start($this->class);
    $this->child = Child::factory()->for($this->owner, 'parent')->create(['display_name' => 'Nihat']);
    $this->home = Games::start($this->child, seed: 2);
});

/**
 * The clip of a display name, as a board should receive it.
 */
function nameClipOf(NameVoices $voices, string $displayName): string
{
    return (string) file_get_contents((string) $voices->file($displayName));
}

function assertNameClip(TestResponse $response, string $audio): void
{
    $response->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Disposition')
        ->assertHeaderMissing('ETag')
        ->assertHeaderMissing('Last-Modified');

    expect($response->getContent())->toBe($audio)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')->not->toContain('public');
}

it('serves a student\'s clip to the teacher\'s board of the class game', function (): void {
    $this->actingAs($this->owner);

    assertNameClip($this->get(route('games.players.voice', [$this->game, $this->aysel->id])), nameClipOf($this->voices, 'Aysel'));
    assertNameClip($this->get(route('games.players.voice', [$this->game, $this->ali->id])), nameClipOf($this->voices, 'Əli'));
});

it('serves the child\'s clip to the parent\'s home board', function (): void {
    $this->actingAs($this->owner);

    assertNameClip($this->get(route('games.players.voice', [$this->home, $this->child->id])), nameClipOf($this->voices, 'Nihat'));
    // The class's students are not the home game's players.
    $this->get(route('games.players.voice', [$this->home, $this->aysel->id]))->assertNotFound();
});

it('serves a guest board only the players of the game it shows', function (): void {
    $board = GuestBoards::showing($this->game);
    $bearer = GuestBoards::bearer($board['token']);

    assertNameClip($this->get(route('games.players.voice', [$this->game, $this->aysel->id]), $bearer), nameClipOf($this->voices, 'Aysel'));

    // The owner's home game and another class game: not this board's.
    $this->get(route('games.players.voice', [$this->home, $this->child->id]), $bearer)->assertNotFound();
    $other = Games::start($this->class, seed: 3);
    $this->get(route('games.players.voice', [$other, $this->aysel->id]), $bearer)->assertNotFound();
});

it('answers 404 for a student of another class, an archived student, a name off the list, and names or praise switched off', function (): void {
    $this->actingAs($this->owner);
    $otherClass = Student::factory()->for(SchoolClass::factory()->for($this->owner, 'teacher'))->create(['display_name' => 'Aysel']);
    $archived = Student::factory()->for($this->class)->archived()->create(['display_name' => 'Aysel']);
    $voice = fn (string $player): TestResponse => $this->get(route('games.players.voice', [$this->game, $player]));

    $voice($otherClass->id)->assertNotFound();
    $voice($archived->id)->assertNotFound();
    // Listed, but no clip made yet; and not on the list at all.
    $voice($this->zehra->id)->assertNotFound();
    $voice($this->ramin->id)->assertNotFound();
    // Not a student of any class: the child, and an id of nothing.
    $voice($this->child->id)->assertNotFound();
    $voice('01jbz0000000000000000000ab')->assertNotFound();

    foreach ([['names' => false], ['praise' => false], ['prompt' => false, 'praise' => false, 'names' => true]] as $choice) {
        $this->owner->forceFill(['board_voice' => $choice])->save();
        $voice($this->aysel->id)->assertNotFound();
    }

    $this->owner->forceFill(['board_voice' => ['prompt' => false, 'praise' => true]])->save();
    $voice($this->aysel->id)->assertOk();
});

it('takes ids only: a name in place of the player is no route', function (): void {
    $this->actingAs($this->owner);

    $this->get('/games/'.$this->game->id.'/players/Aysel/voice')->assertNotFound();
    $this->get('/games/'.$this->game->id.'/players/'.$this->voices->line('Aysel')->hash.'/voice')->assertNotFound();
});

it('answers no-store with no file name in any header, and logs no name', function (): void {
    $this->actingAs($this->owner);
    Log::spy();
    $response = $this->get(route('games.players.voice', [$this->game, $this->aysel->id]));
    $hash = $this->voices->line('Aysel')->hash;

    foreach ($response->headers->all() as $name => $values) {
        expect(implode(' ', $values))->not->toContain($hash)->not->toContain('Aysel')->not->toContain(NameVoices::FOLDER, $name);
    }

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('puts no name and no hash in any URL of the board props', function (): void {
    $this->actingAs($this->owner);
    $hashes = array_keys($this->voices->needed());
    $url = static fn (string $game, string $player): string => "/games/{$game}/players/{$player}/voice";

    $this->get(route('games.board', $this->game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('students.0.name_audio', $url($this->game->id, $this->aysel->id))
            ->where('students.1.name_audio', $url($this->game->id, $this->ali->id))
            // Listed without a clip, and off the list: silent.
            ->where('students.2.name_audio', null)
            ->where('students.3.name_audio', null)
            ->where('voice.names', true));

    $this->get(route('games.board', $this->home))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('child.name_audio', $url($this->home->id, $this->child->id)));

    // The guest board's JSON carries the same.
    $board = GuestBoards::showing($this->game);
    $json = $this->getJson(route('guest-boards.show', $board['board']), GuestBoards::bearer($board['token']))->assertOk()->json();
    $urls = [];
    array_walk_recursive($json, static function (mixed $value) use (&$urls): void {
        if (is_string($value) && (str_starts_with($value, '/') || str_starts_with($value, 'http'))) {
            $urls[] = $value;
        }
    });

    expect($urls)->toContain($url($this->game->id, $this->aysel->id));

    foreach ($urls as $found) {
        foreach (['Aysel', 'Əli', 'Nihat', 'aysel', NameVoices::FOLDER, ...$hashes] as $secret) {
            expect(rawurldecode($found))->not->toContain($secret);
        }
    }
});

it('sends no name audio while the owner has names or praise off, and says so in voice', function (array $choice): void {
    $this->owner->forceFill(['board_voice' => $choice])->save();
    $this->actingAs($this->owner);

    $this->get(route('games.board', $this->game))
        ->assertInertia(fn (Assert $page) => $page
            ->where('students.0.name_audio', null)
            ->where('students.1.name_audio', null)
            ->where('voice.names', false));
    $this->get(route('games.board', $this->home))
        ->assertInertia(fn (Assert $page) => $page->where('child.name_audio', null));
})->with([
    'names off' => [['prompt' => true, 'praise' => true, 'names' => false]],
    'praise off' => [['prompt' => true, 'praise' => false]],
]);
