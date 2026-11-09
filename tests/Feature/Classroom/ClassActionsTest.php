<?php

use App\Domain\Classroom\Actions\ArchiveClass;
use App\Domain\Classroom\Actions\CreateClass;
use App\Domain\Classroom\Actions\DeleteClass;
use App\Domain\Classroom\Actions\RestoreClass;
use App\Domain\Classroom\Actions\UpdateClass;
use App\Domain\Classroom\Actions\UpdateLearnedLetters;
use App\Domain\Classroom\Data\ClassData;
use App\Domain\Classroom\Data\LearnedLettersData;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Language\Support\Alphabet;
use App\Models\User;

it('creates a class for a teacher with every letter learned', function (): void {
    $teacher = User::factory()->create();

    $class = app(CreateClass::class)->handle($teacher, ClassData::validate(['name' => ' 1A ', 'grade' => 1]));

    expect($class->fresh())
        ->user_id->toBe($teacher->id)
        ->name->toBe('1A')
        ->grade->toBe(1)
        ->learned_letters->toBeNull()
        ->archived_at->toBeNull()
        ->and($class->id)->toBeString()->toHaveLength(26)
        ->and(SchoolClass::query()->ownedBy($teacher)->count())->toBe(1)
        ->and(SchoolClass::query()->ownedBy(User::factory()->create())->count())->toBe(0);
});

it('updates the name and the grade', function (): void {
    $class = SchoolClass::factory()->create(['name' => '1A', 'grade' => 1]);

    app(UpdateClass::class)->handle($class, new ClassData('2B', null));

    expect($class->fresh())->name->toBe('2B')->grade->toBeNull();
});

it('archives and restores a class, and repeating either changes nothing', function (): void {
    $this->travelTo('2026-11-09 10:00:00');
    $class = SchoolClass::factory()->create();

    app(ArchiveClass::class)->handle($class);
    $this->travel(1)->day();
    app(ArchiveClass::class)->handle($class);

    expect($class->fresh()->archived_at?->toDateTimeString())->toBe('2026-11-09 10:00:00')
        ->and(SchoolClass::query()->active()->count())->toBe(0)
        ->and(SchoolClass::query()->archived()->count())->toBe(1);

    app(RestoreClass::class)->handle($class);
    app(RestoreClass::class)->handle($class);

    expect($class->fresh()->archived_at)->toBeNull();
});

it('deletes a class with its students', function (): void {
    $class = SchoolClass::factory()->create();
    Student::factory()->count(3)->for($class)->create();
    $other = Student::factory()->create();

    app(DeleteClass::class)->handle($class);

    expect(SchoolClass::query()->find($class->id))->toBeNull()
        ->and(Student::query()->pluck('id')->all())->toBe([$other->id]);
});

it('deletes the classes of a deleted teacher', function (): void {
    $class = SchoolClass::factory()->create();
    Student::factory()->for($class)->create();

    $class->teacher->delete();

    expect(SchoolClass::query()->count())->toBe(0)
        ->and(Student::query()->count())->toBe(0);
});

it('stores learned letters lowercase in alphabet order', function (): void {
    $class = SchoolClass::factory()->create();

    app(UpdateLearnedLetters::class)->handle($class, LearnedLettersData::validate(['letters' => ['ş', 'a', 'ə', 'b']]));

    expect($class->fresh()->learned_letters)->toBe(['a', 'b', 'ə', 'ş']);

    app(UpdateLearnedLetters::class)->handle($class, new LearnedLettersData([]));

    expect($class->fresh()->learned_letters)->toBe([]);
});

it('stores all 32 learned letters as null', function (): void {
    $class = SchoolClass::factory()->create(['learned_letters' => ['a']]);

    app(UpdateLearnedLetters::class)->handle($class, new LearnedLettersData(Alphabet::letters()));

    expect($class->fresh()->learned_letters)->toBeNull();
});
