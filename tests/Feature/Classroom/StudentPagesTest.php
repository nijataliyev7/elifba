<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\Avatars;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Students on the class page: paste a list → preview → confirm, edit,
 * reorder, archive, restore, delete. The pasted list holds surnames, so the
 * preview must store, flash and log nothing of it (docs/architecture.md §7).
 */

const PASTED_LIST = "1. Məmmədov Əli Rəşid oğlu\n2. Həsənova Aysel\n3. Aysel Quliyeva\n4. Kərimli Leyla\n5. Nərgiz Əliyeva\n";

const PASTED_SURNAMES = ['Məmmədov', 'Rəşid', 'Həsənova', 'Quliyeva', 'Kərimli', 'Əliyeva'];

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->schoolClass = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '1A']);
    $this->actingAs($this->teacher);
});

/**
 * @return list<string>
 */
function classRoster(SchoolClass $class): array
{
    return $class->students()->active()->ordered()->pluck('display_name')->all();
}

/**
 * Every log line written while the callback runs.
 *
 * @return list<string>
 */
function loggedDuring(callable $callback): array
{
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$lines): void {
        $lines[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
    });

    $callback();

    return $lines;
}

describe('paste preview', function (): void {
    it('suggests first names, adds initials only where two collide, and writes nothing', function (): void {
        $before = DB::table('students')->count();

        $this->postJson(route('classes.students.preview', $this->schoolClass), ['text' => PASTED_LIST])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('lines.*.display_name', ['Əli', 'Aysel H.', 'Aysel Q.', 'Leyla', 'Nərgiz'])
            ->assertJsonPath('lines.0', [
                'line' => 1,
                'text' => 'Məmmədov Əli Rəşid oğlu',
                'display_name' => 'Əli',
                'flags' => ['surname_first'],
                'addable' => true,
            ])
            ->assertJsonPath('lines.*.addable', [true, true, true, true, true]);

        expect(DB::table('students')->count())->toBe($before);
    });

    it('marks a name the class already has as taken', function (): void {
        Student::factory()->for($this->schoolClass)->create(['display_name' => 'Əli']);

        $this->postJson(route('classes.students.preview', $this->schoolClass), ['text' => "Əli\nVüsal"])
            ->assertOk()
            ->assertJsonPath('lines.0.flags', ['in_class', 'taken'])
            ->assertJsonPath('lines.0.addable', false)
            ->assertJsonPath('lines.1.addable', true);
    });

    it('keeps the pasted text out of the session, the database and the log', function (): void {
        $lines = loggedDuring(function (): void {
            // Without the JSON header, as a plain form post would come.
            $this->post(route('classes.students.preview', $this->schoolClass), ['text' => PASTED_LIST])->assertOk();
        });

        $stored = json_encode([DB::table('students')->get(), DB::table('school_classes')->get(), session()->all()], JSON_UNESCAPED_UNICODE);

        foreach (PASTED_SURNAMES as $surname) {
            expect($stored)->not->toContain($surname)
                ->and(implode("\n", $lines))->not->toContain($surname);
        }
    });

    it('answers a bad paste with JSON errors, never a redirect that flashes it', function (array $input): void {
        $response = $this->post(route('classes.students.preview', $this->schoolClass), $input);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('text')
            ->assertSessionMissing('_old_input')
            ->assertSessionMissing('errors');

        expect(json_encode(session()->all(), JSON_UNESCAPED_UNICODE))->not->toContain('Məmmədov');
    })->with([
        'empty' => [['text' => '']],
        'too long' => [['text' => str_repeat("Məmmədov Əli\n", 800)]],
    ]);

    it('has no GET preview, so the list never goes into a URL', function (): void {
        $this->get(route('classes.students.preview', $this->schoolClass).'?text=M%C9%99mm%C9%99dov')
            ->assertMethodNotAllowed();
    });
});

describe('confirming the preview', function (): void {
    it('adds the confirmed names in order with avatars, and only display names', function (): void {
        $this->post(route('classes.students.store', $this->schoolClass), [
            'names' => ['Əli', 'Aysel H.', 'Aysel Q.', 'Leyla', 'Nərgiz'],
        ])->assertRedirect(route('classes.show', $this->schoolClass))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Əlavə edilən şagirdlər: 5.']);

        $students = $this->schoolClass->students()->ordered()->get();

        expect($students->pluck('display_name')->all())->toBe(['Əli', 'Aysel H.', 'Aysel Q.', 'Leyla', 'Nərgiz'])
            ->and($students->pluck('position')->all())->toBe([1, 2, 3, 4, 5])
            ->and($students->pluck('avatar')->unique())->toHaveCount(5)
            ->and($students->every(fn (Student $student): bool => Avatars::exists($student->avatar)))->toBeTrue();

        $this->get(route('classes.show', $this->schoolClass))
            ->assertInertia(fn ($page) => $page->has('students', 5)->where('students.0.display_name', 'Əli'));
    });

    it('goes from preview to five students in one flow', function (): void {
        $lines = $this->postJson(route('classes.students.preview', $this->schoolClass), ['text' => PASTED_LIST])
            ->json('lines');

        $names = array_column(array_filter($lines, fn (array $line): bool => $line['addable']), 'display_name');

        $this->post(route('classes.students.store', $this->schoolClass), ['names' => $names])->assertSessionHasNoErrors();

        expect(classRoster($this->schoolClass))->toBe(['Əli', 'Aysel H.', 'Aysel Q.', 'Leyla', 'Nərgiz']);
    });

    it('refuses a name the class already has, on that name, and adds none', function (): void {
        Student::factory()->for($this->schoolClass)->create(['display_name' => 'Aysel']);

        $this->from(route('classes.show', $this->schoolClass))
            ->post(route('classes.students.store', $this->schoolClass), ['names' => ['Leyla', 'aysel']])
            ->assertRedirect(route('classes.show', $this->schoolClass))
            ->assertSessionHasErrors(['names.1' => __('students.errors.name_taken')])
            ->assertSessionMissing('_old_input.names');

        expect(classRoster($this->schoolClass))->toBe(['Aysel']);
    });

    it('refuses a name given twice', function (): void {
        $this->post(route('classes.students.store', $this->schoolClass), ['names' => ['Leyla', 'LEYLA']])
            ->assertSessionHasErrors(['names.1' => __('students.errors.name_repeated')]);

        expect(classRoster($this->schoolClass))->toBe([]);
    });

    it('refuses a surname, a foreign letter or more than 50 names', function (array $names, string $field): void {
        $this->post(route('classes.students.store', $this->schoolClass), ['names' => $names])
            ->assertSessionHasErrors($field);

        expect(classRoster($this->schoolClass))->toBe([]);
    })->with([
        'a number' => [['Əli 2'], 'names.0'],
        'a foreign letter' => [['William'], 'names.0'],
        'too long' => [[str_repeat('a', 41)], 'names.0'],
        'too many' => [array_map(fn (int $i): string => 'Ad'.str_repeat('a', $i), range(1, 51)), 'names'],
    ]);
});

describe('editing students', function (): void {
    it('renames a student and changes the avatar', function (): void {
        $student = Student::factory()->for($this->schoolClass)->create(['display_name' => 'Aysel', 'avatar' => Avatars::keys()[0]]);

        $this->patch(route('classes.students.update', [$this->schoolClass, $student]), [
            'display_name' => 'Aysel M.',
            'avatar' => Avatars::keys()[3],
        ])->assertRedirect(route('classes.show', $this->schoolClass));

        expect($student->fresh())->display_name->toBe('Aysel M.')->avatar->toBe(Avatars::keys()[3]);
    });

    it('refuses a rename to a name another student has, or an unknown avatar', function (array $input, string $field): void {
        Student::factory()->for($this->schoolClass)->create(['display_name' => 'Leyla']);
        $student = Student::factory()->for($this->schoolClass)->create(['display_name' => 'Aysel', 'avatar' => Avatars::keys()[0]]);

        $this->patch(route('classes.students.update', [$this->schoolClass, $student]), [
            'display_name' => 'Aysel',
            'avatar' => Avatars::keys()[0],
            ...$input,
        ])->assertSessionHasErrors($field)->assertSessionMissing('_old_input.display_name');

        expect($student->fresh())->display_name->toBe('Aysel')->avatar->toBe(Avatars::keys()[0]);
    })->with([
        'taken name' => [['display_name' => 'leyla'], 'display_name'],
        'unknown avatar' => [['avatar' => 'dragon'], 'avatar'],
    ]);

    it('reorders the class', function (): void {
        $first = Student::factory()->for($this->schoolClass)->create(['display_name' => 'Aysel']);
        $second = Student::factory()->for($this->schoolClass)->create(['display_name' => 'Əli']);
        $third = Student::factory()->for($this->schoolClass)->create(['display_name' => 'Leyla']);

        $this->put(route('classes.students.order', $this->schoolClass), [
            'student_ids' => [$third->id, $first->id, $second->id],
        ])->assertRedirect(route('classes.show', $this->schoolClass));

        expect(classRoster($this->schoolClass))->toBe(['Leyla', 'Aysel', 'Əli']);
    });

    it('archives, restores and deletes a student', function (): void {
        $student = Student::factory()->for($this->schoolClass)->create(['display_name' => 'Aysel']);

        $this->post(route('classes.students.archive', [$this->schoolClass, $student]))->assertRedirect();
        expect(classRoster($this->schoolClass))->toBe([]);

        $this->delete(route('classes.students.restore', [$this->schoolClass, $student]))->assertRedirect();
        expect(classRoster($this->schoolClass))->toBe(['Aysel']);

        $this->delete(route('classes.students.destroy', [$this->schoolClass, $student]))->assertRedirect();
        expect(Student::query()->whereKey($student->id)->exists())->toBeFalse();
    });

    it('blocks restoring a student while an active one has the name', function (): void {
        $archived = Student::factory()->for($this->schoolClass)->archived()->create(['display_name' => 'Aysel']);
        Student::factory()->for($this->schoolClass)->create(['display_name' => 'Aysel']);

        $this->delete(route('classes.students.restore', [$this->schoolClass, $archived]))
            ->assertSessionHasErrors(['display_name' => __('students.errors.restore_name_taken')]);

        expect($archived->fresh()?->archived_at)->not->toBeNull();
    });

    it('never puts a child\'s name into a flash message', function (): void {
        $student = Student::factory()->for($this->schoolClass)->create(['display_name' => 'Aysel']);

        $this->patch(route('classes.students.update', [$this->schoolClass, $student]), [
            'display_name' => 'Nərmin',
            'avatar' => Avatars::keys()[1],
        ]);
        $this->post(route('classes.students.store', $this->schoolClass), ['names' => ['Zəhra']]);

        expect(json_encode(session()->all(), JSON_UNESCAPED_UNICODE))
            ->not->toContain('Nərmin')
            ->not->toContain('Zəhra');
    });
});
