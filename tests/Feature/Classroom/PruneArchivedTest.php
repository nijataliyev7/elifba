<?php

use App\Domain\Classroom\Actions\PruneArchived;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Archived classes and students are deleted after elifba.retention_days
 * (docs/architecture.md §7).
 */

it('keeps archived rows until the retention period has passed, then deletes them', function (): void {
    config(['elifba.retention_days' => 400]);
    $this->travelTo('2025-10-05 12:00:00');

    $archivedClass = SchoolClass::factory()->archived()->create();
    Student::factory()->for($archivedClass)->create();
    $class = SchoolClass::factory()->create();
    $leaver = Student::factory()->for($class)->archived()->create();
    $stayer = Student::factory()->for($class)->create();

    $this->travelTo('2026-11-09 12:00:00'); // exactly 400 days later

    expect(app(PruneArchived::class)->handle())->toBe(['classes' => 0, 'students' => 0]);

    $this->travel(1)->second();

    expect(app(PruneArchived::class)->handle())->toBe(['classes' => 1, 'students' => 1])
        ->and(SchoolClass::query()->pluck('id')->all())->toBe([$class->id])
        ->and(Student::query()->pluck('id')->all())->toBe([$stayer->id])
        ->and(Student::query()->find($leaver->id))->toBeNull();
});

it('keeps rows archived within the period, and active ones', function (): void {
    $this->travelTo('2026-11-09 12:00:00');
    SchoolClass::factory()->archived()->create();
    Student::factory()->archived()->create();
    $this->travel(399)->days();

    app(PruneArchived::class)->handle();

    expect(SchoolClass::query()->count())->toBe(2) // the student's own class is active
        ->and(Student::query()->count())->toBe(1);
});

it('follows the configured retention period', function (): void {
    config(['elifba.retention_days' => 30]);
    $this->travelTo('2026-11-09 12:00:00');
    Student::factory()->archived()->create();
    $this->travel(31)->days();

    expect(app(PruneArchived::class)->handle()['students'])->toBe(1);
});

it('runs every day from the scheduler', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'classroom:prune-archived'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');

    $this->artisan('classroom:prune-archived')
        ->expectsOutput('Deleted 0 archived classes and 0 archived students.')
        ->assertSuccessful();
});
