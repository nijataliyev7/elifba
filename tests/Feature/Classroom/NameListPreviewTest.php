<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Classroom\Support\NameListParser;

/*
 * The preview against a real class: only its active students count, and it
 * writes nothing.
 */

it('previews against the class\'s active students and writes nothing', function (): void {
    $class = SchoolClass::factory()->create();
    Student::factory()->for($class)->create(['display_name' => 'Aysel']);
    Student::factory()->for($class)->archived()->create(['display_name' => 'Əli']);
    Student::factory()->create(['display_name' => 'Leyla']); // another class
    $before = Student::query()->get()->toArray();

    $lines = app(NameListParser::class)->parseForClass($class, "Məmmədova Aysel\nHəsənov Əli\nQuliyeva Leyla");

    expect(array_map(fn ($line): ?string => $line->display_name, $lines))->toBe(['Aysel Məmmədova', 'Əli Həsənov', 'Leyla Quliyeva'])
        ->and(array_map(fn ($line): array => $line->toArray()['flags'], $lines))->toBe([['surname_first', 'in_class'], ['surname_first'], ['surname_first']])
        ->and(Student::query()->get()->toArray())->toBe($before);
});
