<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\Avatars;
use App\Domain\Language\Support\Alphabet;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\NameClips;

/*
 * The classes page and the class page: create, rename, archive, restore,
 * delete, learned letters. Isolation between teachers is in
 * TeacherIsolationTest.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->actingAs($this->teacher);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function ownClass(User $teacher, array $attributes = []): SchoolClass
{
    return SchoolClass::factory()->for($teacher, 'teacher')->create($attributes);
}

describe('classes page', function (): void {
    it('lists active classes in Azerbaijani order and archived ones apart', function (): void {
        // Letters in the 32-letter order (I before İ); digits after letters (AzCollator).
        foreach (['İnci', '1Ə', 'Çiçək', '1B', 'Ağ', '1A', 'Ilğım'] as $name) {
            ownClass($this->teacher, ['name' => $name]);
        }
        $archived = ownClass($this->teacher, ['name' => '4B', 'archived_at' => now()]);
        $withStudents = SchoolClass::query()->where('name', 'Çiçək')->sole();
        Student::factory()->for($withStudents)->count(2)->create();
        Student::factory()->for($withStudents)->archived()->create();

        $this->get(route('classes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('classes/index')
                ->where('classes', fn ($classes): bool => $classes->pluck('name')->all() === ['Ağ', 'Çiçək', 'Ilğım', 'İnci', '1A', '1B', '1Ə'])
                ->where('classes.1.students_count', 2)
                ->where('archivedClasses.0.id', $archived->id)
                ->has('archivedClasses', 1)
                ->where('retentionDays', 400));
    });

    it('needs a verified teacher', function (): void {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('classes.index'))
            ->assertRedirect(route('verification.notice'));
    });
});

describe('creating and editing a class', function (): void {
    it('creates a class and opens its page', function (): void {
        $response = $this->post(route('classes.store'), ['name' => '  1A ', 'grade' => '1']);

        $class = SchoolClass::query()->sole();
        $response->assertRedirect(route('classes.show', $class));

        expect($class)
            ->user_id->toBe($this->teacher->id)
            ->name->toBe('1A')
            ->grade->toBe(1)
            ->learned_letters->toBeNull();
    });

    it('creates a class without a grade', function (): void {
        $this->post(route('classes.store'), ['name' => '1A', 'grade' => ''])->assertSessionHasNoErrors();

        expect(SchoolClass::query()->sole()->grade)->toBeNull();
    });

    it('rejects a class without a name, a long name or a grade outside 1–4', function (array $input, string $field): void {
        $this->from(route('classes.index'))
            ->post(route('classes.store'), $input)
            ->assertRedirect(route('classes.index'))
            ->assertSessionHasErrors($field);

        expect(SchoolClass::query()->count())->toBe(0);
    })->with([
        'no name' => [['name' => ' '], 'name'],
        'too long' => [['name' => str_repeat('A', 41)], 'name'],
        'grade 5' => [['name' => '5A', 'grade' => 5], 'grade'],
        'grade 0' => [['name' => '0A', 'grade' => 0], 'grade'],
    ]);

    it('renames a class and changes its grade', function (): void {
        $class = ownClass($this->teacher, ['name' => '1A', 'grade' => 1]);

        $this->patch(route('classes.update', $class), ['name' => '2A', 'grade' => 2])
            ->assertRedirect(route('classes.show', $class));

        expect($class->fresh())->name->toBe('2A')->grade->toBe(2);
    });
});

describe('the class page', function (): void {
    it('shows students in their order, archived ones by name, and the alphabet from the server', function (): void {
        $class = ownClass($this->teacher, ['name' => '1A']);
        Student::factory()->for($class)->create(['display_name' => 'Zəhra', 'position' => 2]);
        Student::factory()->for($class)->create(['display_name' => 'Əli', 'position' => 1]);
        Student::factory()->for($class)->archived()->create(['display_name' => 'Vüsal']);
        Student::factory()->for($class)->archived()->create(['display_name' => 'Çinarə']);

        $this->get(route('classes.show', $class))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('classes/show')
                ->where('schoolClass.id', $class->id)
                ->where('schoolClass.archived', false)
                ->where('schoolClass.learned_letters', Alphabet::letters())
                ->where('students', fn ($students): bool => $students->pluck('display_name')->all() === ['Əli', 'Zəhra'])
                ->where('archivedStudents', fn ($students): bool => $students->pluck('display_name')->all() === ['Çinarə', 'Vüsal'])
                ->has('alphabet', 32)
                ->where('alphabet.0', ['lower' => 'a', 'upper' => 'A'])
                ->where('alphabet.13', ['lower' => 'i', 'upper' => 'İ'])
                ->where('alphabet.12', ['lower' => 'ı', 'upper' => 'I'])
                ->where('avatars', Avatars::all())
                ->where('avatars.0', ['key' => 'panda', 'url' => url('content/avatars/panda.svg')])
                ->where('limits', ['paste_length' => 10000, 'names' => 50]));
    });

    it('marks the active students whose name the board says, from the name and the list only', function (): void {
        NameClips::fake(['Əli', 'Aysel'], ['Zəhra']);
        $class = ownClass($this->teacher);
        Student::factory()->for($class)->create(['display_name' => 'Əli M.', 'position' => 1]);
        Student::factory()->for($class)->create(['display_name' => 'Zəhra', 'position' => 2]);
        Student::factory()->for($class)->create(['display_name' => 'Ayşən-Nur', 'position' => 3]);
        Student::factory()->for($class)->archived()->create(['display_name' => 'Aysel']);

        $this->get(route('classes.show', $class))
            ->assertInertia(fn (Assert $page) => $page
                ->where('students.0.has_voice', true)
                // Listed without a clip yet, and a hyphenated name: shown, not said.
                ->where('students.1.has_voice', false)
                ->where('students.2.has_voice', false)
                // Archived students are not at the board.
                ->where('archivedStudents.0', fn ($student): bool => ! $student->has('has_voice')));

        // Names or praise off: the account says no name, so no mark either way.
        foreach ([['names' => false], ['praise' => false]] as $choice) {
            $this->teacher->forceFill(['board_voice' => $choice])->save();

            $this->get(route('classes.show', $class))
                ->assertInertia(fn (Assert $page) => $page->where('students.0.has_voice', null)->where('students.1.has_voice', null));
        }
    });

    it('offers the list\'s spelling of a name typed without the Azerbaijani letters', function (): void {
        NameClips::fake(['Lalə', 'Aysel']);
        $class = ownClass($this->teacher);
        Student::factory()->for($class)->create(['display_name' => 'Lale M.', 'position' => 1]);
        Student::factory()->for($class)->create(['display_name' => 'Aysel', 'position' => 2]);
        Student::factory()->for($class)->create(['display_name' => 'Ramin', 'position' => 3]);

        $this->get(route('classes.show', $class))
            ->assertInertia(fn (Assert $page) => $page
                // The board says «Lalə!» either way; the page offers the spelling it shows.
                ->where('students.0.has_voice', true)
                ->where('students.0.voice_spelling', 'Lalə M.')
                ->where('students.1.voice_spelling', null)
                ->where('students.2.voice_spelling', null));

        $this->teacher->forceFill(['board_voice' => ['names' => false]])->save();

        $this->get(route('classes.show', $class))
            ->assertInertia(fn (Assert $page) => $page->where('students.0.voice_spelling', null));
    });

    it('shows an archived class, marked as archived', function (): void {
        $class = ownClass($this->teacher, ['archived_at' => now()]);

        $this->get(route('classes.show', $class))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('schoolClass.archived', true));
    });
});

describe('archive, restore and delete', function (): void {
    it('archives a class and restores it', function (): void {
        $class = ownClass($this->teacher);

        $this->post(route('classes.archive', $class))->assertRedirect(route('classes.index'));
        expect($class->fresh()?->archived_at)->not->toBeNull();

        $this->delete(route('classes.restore', $class))->assertRedirect(route('classes.show', $class));
        expect($class->fresh()?->archived_at)->toBeNull();
    });

    it('deletes a class with its students', function (): void {
        $class = ownClass($this->teacher);
        Student::factory()->for($class)->count(3)->create();
        $other = ownClass($this->teacher);
        Student::factory()->for($other)->create();

        $this->delete(route('classes.destroy', $class))->assertRedirect(route('classes.index'));

        expect(SchoolClass::query()->pluck('id')->all())->toBe([$other->id])
            ->and(Student::query()->count())->toBe(1);
    });
});

describe('learned letters', function (): void {
    it('saves the switched-on letters in alphabet order', function (): void {
        $class = ownClass($this->teacher);

        $this->put(route('classes.learned-letters.update', $class), ['letters' => ['ə', 'a', 'l', 'm']])
            ->assertRedirect(route('classes.show', $class));

        expect($class->fresh()?->learned_letters)->toBe(['a', 'ə', 'l', 'm']);

        $this->get(route('classes.show', $class))
            ->assertInertia(fn (Assert $page) => $page->where('schoolClass.learned_letters', ['a', 'ə', 'l', 'm']));
    });

    it('stores all 32 as null and none as an empty list', function (): void {
        $class = ownClass($this->teacher, ['learned_letters' => ['a']]);

        $this->put(route('classes.learned-letters.update', $class), ['letters' => Alphabet::letters()]);
        expect($class->fresh()?->learned_letters)->toBeNull();

        $this->putJson(route('classes.learned-letters.update', $class), ['letters' => []]);
        expect($class->fresh()?->learned_letters)->toBe([]);
    });

    it('rejects a letter outside the alphabet or an uppercase one', function (string $letter): void {
        $class = ownClass($this->teacher);

        $this->from(route('classes.show', $class))
            ->put(route('classes.learned-letters.update', $class), ['letters' => ['a', $letter]])
            ->assertSessionHasErrors('letters.1');

        expect($class->fresh()?->learned_letters)->toBeNull();
    })->with(['w', 'A', 'ә', 'ab']);
});
