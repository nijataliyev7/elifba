<?php

use App\Domain\Classroom\Data\StudentData;
use App\Domain\Content\Support\Avatars;
use App\Domain\Family\Actions\AddChild;
use App\Domain\Family\Actions\DeleteChild;
use App\Domain\Family\Actions\ReorderChildren;
use App\Domain\Family\Actions\UpdateChild;
use App\Domain\Family\Actions\UpdateChildLetters;
use App\Domain\Family\Data\ChildData;
use App\Domain\Family\Data\ChildLettersData;
use App\Domain\Family\Data\ReorderChildrenData;
use App\Domain\Family\Models\Child;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Models\GameAnswer;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\Games;
use Tests\Support\GuestBoards;

/*
 * A parent's children (docs/architecture.md §1 `children`, §7 "Parents and
 * their children", ADR 011, ADR 013): a first name and an avatar, at most
 * `elifba.children.max`, unique per parent with Azerbaijani casing, the
 * learned letters, the parent's order, and real deletion.
 */

beforeEach(function (): void {
    $this->parent = User::factory()->parent()->create();
});

/**
 * The field errors an action refused with.
 *
 * @return array<string, list<string>>
 */
function childErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('adds a child with the next avatar', function (): void {
    $keys = Avatars::keys();
    $add = app(AddChild::class);

    $aysel = $add->handle($this->parent, ChildData::validate(['display_name' => '  Aysel ']));
    $tural = $add->handle($this->parent, ChildData::validate(['display_name' => 'Tural']));
    // A chosen avatar is kept; the next one skips it.
    $nigar = $add->handle($this->parent, ChildData::validate(['display_name' => 'Nigar', 'avatar' => $keys[3]]));
    $leyla = $add->handle($this->parent, ChildData::validate(['display_name' => 'Leyla']));

    expect($aysel->only(['display_name', 'avatar', 'learned_letters', 'position', 'user_id']))->toBe([
        'display_name' => 'Aysel',
        'avatar' => $keys[0],
        'learned_letters' => null,
        'position' => 1,
        'user_id' => $this->parent->id,
    ])
        ->and([$tural->avatar, $tural->position])->toBe([$keys[1], 2])
        ->and([$nigar->avatar, $nigar->position])->toBe([$keys[3], 3])
        ->and([$leyla->avatar, $leyla->position])->toBe([$keys[2], 4]);

    // Another parent's children do not count.
    $other = $add->handle(User::factory()->parent()->create(), ChildData::validate(['display_name' => 'Aysel']));
    expect([$other->avatar, $other->position])->toBe([$keys[0], 1]);
});

it('adds a child through the page and lands on the child\'s page, never with the name in the URL', function (): void {
    $response = $this->actingAs($this->parent)->post(route('children.store'), ['display_name' => 'Aysel']);
    $child = Child::query()->sole();

    $response->assertRedirect(route('children.show', $child));
    expect($response->headers->get('Location'))->not->toContain('Aysel')
        ->and($child->display_name)->toBe('Aysel');
});

it('refuses a name the parent already uses, compared with Azerbaijani casing', function (): void {
    $add = app(AddChild::class);
    $ilkin = $add->handle($this->parent, ChildData::validate(['display_name' => 'İlkin']));
    $aysel = $add->handle($this->parent, ChildData::validate(['display_name' => 'Aysel']));

    // "ilkin" is "İlkin"; "Ilkin" (I = ı) is another name.
    expect(childErrors(fn () => $add->handle($this->parent, ChildData::validate(['display_name' => 'ilkin']))))
        ->toBe(['display_name' => [__('children.errors.taken')]])
        ->and(childErrors(fn () => app(UpdateChild::class)->handle($aysel, ChildData::validate(['display_name' => 'İLKİN']))))
        ->toBe(['display_name' => [__('children.errors.taken')]])
        ->and(__('children.errors.taken'))->not->toContain('lkin');

    $add->handle($this->parent, ChildData::validate(['display_name' => 'Ilkin']));
    // Renaming a child to their own name, or another parent using it, is fine.
    app(UpdateChild::class)->handle($ilkin, ChildData::validate(['display_name' => 'İlkin', 'avatar' => Avatars::keys()[5]]));
    $add->handle(User::factory()->parent()->create(), ChildData::validate(['display_name' => 'İlkin']));

    expect(Child::query()->where('user_id', $this->parent->id)->ordered()->pluck('display_name')->all())->toBe(['İlkin', 'Aysel', 'Ilkin'])
        ->and($ilkin->refresh()->avatar)->toBe(Avatars::keys()[5]);
});

it('refuses an eleventh child', function (): void {
    expect(config('elifba.children.max'))->toBe(10);
    $names = ['Aysel', 'Tural', 'Nigar', 'Leyla', 'Vüsal', 'Əli', 'Murad', 'Günay', 'Rəşad', 'Səbinə'];

    foreach ($names as $name) {
        app(AddChild::class)->handle($this->parent, ChildData::validate(['display_name' => $name]));
    }

    $this->actingAs($this->parent)->from(route('children.index'))
        ->post(route('children.store'), ['display_name' => 'Fidan'])
        ->assertRedirect(route('children.index'))
        ->assertSessionHasErrors(['display_name' => __('children.errors.too_many', ['max' => 10])]);

    expect(Child::query()->where('user_id', $this->parent->id)->count())->toBe(10)
        ->and(__('children.errors.too_many', ['max' => 10]))->toContain('10');
});

it('refuses a surname initial', function (string $name): void {
    expect(childErrors(fn () => ChildData::validate(['display_name' => $name])))
        ->toBe(['display_name' => [__('children.errors.invalid_name')]]);
})->with([
    'an initial with a dot' => ['Aysel M.'],
    'an initial without one' => ['Aysel M'],
    'not a letter' => ['Aysel1'],
    'too long' => [str_repeat('a', 41)],
]);

it('refuses a surname or a patronymic in a child\'s name, with its own message', function (string $name): void {
    expect(childErrors(fn () => ChildData::validate(['display_name' => $name])))
        ->toBe(['display_name' => [__('children.errors.surname')]]);
})->with(['Nihat Əliyev', 'Əliyeva Aysel', 'Aysel Kamal qızı']);

it('takes a first name of more than one word, and a student\'s rule still takes the initial', function (): void {
    expect(ChildData::validate(['display_name' => 'Məhəmməd  Əli'])->display_name)->toBe('Məhəmməd Əli')
        ->and(ChildData::validate(['display_name' => 'Gül-Ayaz'])->display_name)->toBe('Gül-Ayaz')
        ->and(StudentData::validate(['display_name' => 'Aysel M.', 'avatar' => Avatars::keys()[0]])->display_name)->toBe('Aysel M.');
});

it('stores the child\'s learned letters', function (): void {
    $child = Child::factory()->for($this->parent, 'parent')->create();

    app(UpdateChildLetters::class)->handle($child, ChildLettersData::validate(['letters' => ['ə', 'a', 'b']]));
    expect($child->refresh()->learned_letters)->toBe(['a', 'b', 'ə']);

    // All 32 are stored as "all".
    $this->actingAs($this->parent)
        ->put(route('children.learned-letters.update', $child), ['letters' => Alphabet::letters()])
        ->assertRedirect(route('children.show', $child));
    expect($child->refresh()->learned_letters)->toBeNull();

    $this->put(route('children.learned-letters.update', $child), ['letters' => ['w']])->assertSessionHasErrors('letters.0');
    expect($child->refresh()->learned_letters)->toBeNull();
});

it('reorders the parent\'s children and refuses ids that are not exactly theirs', function (): void {
    $a = Child::factory()->for($this->parent, 'parent')->create();
    $b = Child::factory()->for($this->parent, 'parent')->create();
    $c = Child::factory()->for($this->parent, 'parent')->create();
    $foreign = Child::factory()->create();
    $reorder = app(ReorderChildren::class);

    $reorder->handle($this->parent, new ReorderChildrenData([$c->id, $a->id, $b->id]));
    expect(Child::query()->where('user_id', $this->parent->id)->ordered()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);

    foreach ([[$c->id, $a->id], [$c->id, $a->id, $foreign->id], [$c->id, $a->id, $b->id, $foreign->id], [$a->id, $a->id, $b->id]] as $ids) {
        expect(childErrors(fn () => $reorder->handle($this->parent, new ReorderChildrenData($ids))))->toHaveKey('child_ids');
    }

    expect(Child::query()->where('user_id', $this->parent->id)->ordered()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and($foreign->refresh()->position)->toBe(1);
});

it('deletes a child with their games, rounds and answers', function (): void {
    $child = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Aysel']);
    $sibling = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Tural']);
    $game = Games::start($child);
    $kept = Games::start($sibling);
    $board = GuestBoards::showing($game);
    $round = Games::round($game);

    $this->actingAs($this->parent)->postJson(route('games.sync', $game), ['board_epoch' => 0, 'events' => [
        Games::hit($round, Games::target($round)),
    ]])->assertOk();
    expect(GameAnswer::query()->count())->toBe(1);

    $this->delete(route('children.destroy', $child))->assertRedirect(route('children.index'));

    expect(Child::query()->pluck('id')->all())->toBe([$sibling->id])
        ->and(GameSession::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(GameRound::query()->where('game_session_id', $game->id)->count())->toBe(0)
        ->and(GameRound::query()->where('child_id', $child->id)->count())->toBe(0)
        ->and(GameAnswer::query()->count())->toBe(0)
        ->and(GameRound::query()->where('game_session_id', $kept->id)->count())->toBe(5)
        ->and($board['board']->refresh()->game_session_id)->toBeNull();

    app(DeleteChild::class)->handle($sibling);
    expect(Child::query()->count())->toBe(0)->and(GameSession::query()->count())->toBe(0);
});

it('deletes the children with the account', function (): void {
    $child = Child::factory()->for($this->parent, 'parent')->create();
    Games::start($child);

    $this->parent->delete();

    expect(Child::query()->count())->toBe(0)
        ->and(GameSession::query()->count())->toBe(0)
        ->and(GameRound::query()->count())->toBe(0);
});
