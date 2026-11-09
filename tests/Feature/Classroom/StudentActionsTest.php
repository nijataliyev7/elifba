<?php

use App\Domain\Classroom\Actions\AddStudents;
use App\Domain\Classroom\Actions\ArchiveStudent;
use App\Domain\Classroom\Actions\DeleteStudent;
use App\Domain\Classroom\Actions\ReorderStudents;
use App\Domain\Classroom\Actions\RestoreStudent;
use App\Domain\Classroom\Actions\UpdateStudent;
use App\Domain\Classroom\Data\AddStudentsData;
use App\Domain\Classroom\Data\ReorderStudentsData;
use App\Domain\Classroom\Data\StudentData;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\Avatars;
use Illuminate\Validation\ValidationException;

/**
 * @param  list<string>  $names
 * @return list<Student>
 */
function addStudents(SchoolClass $class, array $names): array
{
    return array_values(app(AddStudents::class)->handle($class, new AddStudentsData($names))->all());
}

/**
 * The errors of a ValidationException the callback must throw.
 *
 * @return array<string, list<string>>
 */
function rejected(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Expected a ValidationException.');
}

/**
 * @return list<string>
 */
function roster(SchoolClass $class): array
{
    return $class->students()->active()->ordered()->pluck('display_name')->all();
}

describe('AddStudents', function (): void {
    it('adds names in order, normalized, with positions after the last student', function (): void {
        $class = SchoolClass::factory()->create();
        Student::factory()->for($class)->create(['display_name' => 'Vüsal', 'position' => 7]);

        $added = addStudents($class, [' Əli ', "\u{04D8}ysel M."]);

        expect(array_map(fn (Student $s): array => [$s->display_name, $s->position], $added))
            ->toBe([['Əli', 8], ['Əysel M.', 9]])
            ->and(roster($class))->toBe(['Vüsal', 'Əli', 'Əysel M.']);
    });

    it('gives avatars least-used first, in the file order', function (): void {
        $class = SchoolClass::factory()->create();
        $keys = Avatars::keys();
        Student::factory()->for($class)->create(['display_name' => 'Vüsal', 'avatar' => $keys[0]]);
        Student::factory()->for($class)->create(['display_name' => 'Nərmin', 'avatar' => $keys[2]]);
        Student::factory()->for($class)->archived()->create(['display_name' => 'Kamran', 'avatar' => $keys[1]]);

        $added = addStudents($class, ['Əli', 'Aysel', 'Leyla']);

        expect(array_map(fn (Student $s): string => $s->avatar, $added))->toBe([$keys[1], $keys[3], $keys[4]]);
    });

    it('repeats avatars evenly once every one is used', function (): void {
        $class = SchoolClass::factory()->create();
        $count = count(Avatars::keys());
        $names = array_map(fn (int $i): string => 'Şagird '.str_repeat('a', $i + 1), range(0, $count + 1));

        addStudents($class, array_slice($names, 0, 20));
        addStudents($class, array_slice($names, 20));

        $usage = array_count_values($class->students()->pluck('avatar')->all());

        expect(count($usage))->toBe($count)
            ->and(max($usage) - min($usage))->toBeLessThanOrEqual(1);
    });

    it('rejects a name an active student has, compared with Azerbaijani casing', function (): void {
        $class = SchoolClass::factory()->create();
        addStudents($class, ['İlkin']);

        $errors = rejected(fn () => addStudents($class, ['Aysel', 'ilkin']));

        expect(array_keys($errors))->toBe(['names.1'])
            ->and($errors['names.1'][0])->toBe(__('students.errors.name_taken'))
            ->and($errors['names.1'][0])->not->toContain('ilkin')
            ->and(roster($class))->toBe(['İlkin']); // nothing added
    });

    it('accepts a name that differs by I and İ', function (): void {
        $class = SchoolClass::factory()->create();
        addStudents($class, ['İlkin']);

        addStudents($class, ['Ilkin']);

        expect(roster($class))->toBe(['İlkin', 'Ilkin']);
    });

    it('rejects a name repeated in the list', function (): void {
        $class = SchoolClass::factory()->create();

        $errors = rejected(fn () => addStudents($class, ['Aysel M.', 'Əli', 'aysel m.']));

        expect(array_keys($errors))->toBe(['names.2'])
            ->and($errors['names.2'][0])->toBe(__('students.errors.name_repeated'))
            ->and($class->students()->count())->toBe(0);
    });

    it('ignores archived students and other classes when checking names', function (): void {
        $class = SchoolClass::factory()->create();
        Student::factory()->for($class)->archived()->create(['display_name' => 'Əli']);
        Student::factory()->create(['display_name' => 'Aysel']);

        addStudents($class, ['Əli', 'Aysel']);

        expect(roster($class))->toBe(['Əli', 'Aysel']);
    });
});

describe('UpdateStudent', function (): void {
    it('changes the name and the avatar', function (): void {
        $student = Student::factory()->create(['display_name' => 'Əli', 'avatar' => 'panda']);

        app(UpdateStudent::class)->handle($student, StudentData::validate(['display_name' => ' Əli  M. ', 'avatar' => 'owl']));

        expect($student->fresh())->display_name->toBe('Əli M.')->avatar->toBe('owl');
    });

    it('lets a student keep their own name in another case', function (): void {
        $student = Student::factory()->create(['display_name' => 'ilkin']);

        app(UpdateStudent::class)->handle($student, new StudentData('İlkin', 'panda'));

        expect($student->fresh()->display_name)->toBe('İlkin');
    });

    it('rejects the name of another active student', function (): void {
        $class = SchoolClass::factory()->create();
        [$ali, $aysel] = addStudents($class, ['Əli', 'Aysel']);

        $errors = rejected(fn () => app(UpdateStudent::class)->handle($aysel, new StudentData('ƏLİ', 'panda')));

        expect(array_keys($errors))->toBe(['display_name'])
            ->and($errors['display_name'][0])->not->toContain('ƏLİ')
            ->and($aysel->fresh()->display_name)->toBe('Aysel');
    });

    it('allows the name of an archived student', function (): void {
        $class = SchoolClass::factory()->create();
        [$ali, $aysel] = addStudents($class, ['Əli', 'Aysel']);
        app(ArchiveStudent::class)->handle($ali);

        app(UpdateStudent::class)->handle($aysel, new StudentData('Əli', 'panda'));

        expect(roster($class))->toBe(['Əli']);
    });
});

describe('ReorderStudents', function (): void {
    it('saves the new order without touching updated_at', function (): void {
        $this->travelTo('2026-11-09 08:00:00');
        $class = SchoolClass::factory()->create();
        [$ali, $aysel, $leyla] = addStudents($class, ['Əli', 'Aysel', 'Leyla']);
        $this->travel(1)->hour();

        app(ReorderStudents::class)->handle($class, new ReorderStudentsData([$leyla->id, $ali->id, $aysel->id]));

        expect(roster($class))->toBe(['Leyla', 'Əli', 'Aysel'])
            ->and($class->students()->ordered()->pluck('position')->all())->toBe([1, 2, 3])
            ->and($leyla->fresh()->updated_at->toDateTimeString())->toBe('2026-11-09 08:00:00');
    });

    it('rejects an id from another class, even of the same teacher', function (): void {
        $class = SchoolClass::factory()->create();
        [$ali, $aysel] = addStudents($class, ['Əli', 'Aysel']);
        $sibling = SchoolClass::factory()->for($class->teacher, 'teacher')->create();
        [$foreign] = addStudents($sibling, ['Leyla']);

        $errors = rejected(fn () => app(ReorderStudents::class)->handle($class, new ReorderStudentsData([$aysel->id, $foreign->id])));

        expect(array_keys($errors))->toBe(['student_ids'])
            ->and(roster($class))->toBe(['Əli', 'Aysel'])
            ->and($foreign->fresh()->position)->toBe(1);
    });

    it('rejects an id of another teacher\'s student added to the full list', function (): void {
        $class = SchoolClass::factory()->create();
        [$ali, $aysel] = addStudents($class, ['Əli', 'Aysel']);
        $foreign = Student::factory()->create();

        rejected(fn () => app(ReorderStudents::class)->handle($class, new ReorderStudentsData([$aysel->id, $ali->id, $foreign->id])));

        expect(roster($class))->toBe(['Əli', 'Aysel']);
    });

    it('rejects a list that misses, repeats or includes an archived student', function (callable $ids): void {
        $class = SchoolClass::factory()->create();
        [$ali, $aysel] = addStudents($class, ['Əli', 'Aysel']);
        [$gone] = addStudents($class, ['Kamran']);
        app(ArchiveStudent::class)->handle($gone);

        rejected(fn () => app(ReorderStudents::class)->handle($class, new ReorderStudentsData($ids($ali, $aysel, $gone))));

        expect(roster($class))->toBe(['Əli', 'Aysel']);
    })->with([
        'missing' => [fn (Student $ali, Student $aysel, Student $gone): array => [$aysel->id]],
        'repeated' => [fn (Student $ali, Student $aysel, Student $gone): array => [$aysel->id, $aysel->id]],
        'archived' => [fn (Student $ali, Student $aysel, Student $gone): array => [$aysel->id, $gone->id]],
        'archived extra' => [fn (Student $ali, Student $aysel, Student $gone): array => [$aysel->id, $ali->id, $gone->id]],
        'unknown' => [fn (Student $ali, Student $aysel, Student $gone): array => [$aysel->id, '01JAAAAAAAAAAAAAAAAAAAAAAA']],
    ]);
});

describe('ArchiveStudent and RestoreStudent', function (): void {
    it('archives a student out of the roster, once', function (): void {
        $this->travelTo('2026-11-09 10:00:00');
        $class = SchoolClass::factory()->create();
        [$ali] = addStudents($class, ['Əli', 'Aysel']);

        app(ArchiveStudent::class)->handle($ali);
        $this->travel(1)->day();
        app(ArchiveStudent::class)->handle($ali);

        expect(roster($class))->toBe(['Aysel'])
            ->and($ali->fresh()->archived_at?->toDateTimeString())->toBe('2026-11-09 10:00:00');
    });

    it('restores a student at the end of the order with their avatar', function (): void {
        $class = SchoolClass::factory()->create();
        [$ali] = addStudents($class, ['Əli', 'Aysel', 'Leyla']);
        $avatar = $ali->avatar;
        app(ArchiveStudent::class)->handle($ali);

        app(RestoreStudent::class)->handle($ali);
        app(RestoreStudent::class)->handle($ali);

        expect(roster($class))->toBe(['Aysel', 'Leyla', 'Əli'])
            ->and($ali->fresh())->avatar->toBe($avatar)->position->toBe(4)->archived_at->toBeNull();
    });

    it('blocks a restore while an active student has the name', function (): void {
        $class = SchoolClass::factory()->create();
        [$ali] = addStudents($class, ['Əli']);
        app(ArchiveStudent::class)->handle($ali);
        addStudents($class, ['əli']);

        $errors = rejected(fn () => app(RestoreStudent::class)->handle($ali));

        expect(array_keys($errors))->toBe(['display_name'])
            ->and($errors['display_name'][0])->toBe(__('students.errors.restore_name_taken'))
            ->and($ali->fresh()->archived_at)->not->toBeNull()
            ->and(roster($class))->toBe(['əli']);
    });
});

describe('DeleteStudent', function (): void {
    it('deletes the row', function (): void {
        $class = SchoolClass::factory()->create();
        [$ali, $aysel] = addStudents($class, ['Əli', 'Aysel']);

        app(DeleteStudent::class)->handle($ali);

        expect(Student::query()->find($ali->id))->toBeNull()
            ->and(roster($class))->toBe(['Aysel']);
    });
});
