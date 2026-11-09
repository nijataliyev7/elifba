<?php

use App\Domain\Classroom\Actions\PruneArchived;
use App\Domain\Play\Actions\AbandonStaleGames;
use App\Domain\Play\Actions\PruneGuestBoards;
use App\Domain\Play\Actions\PruneOldGames;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('classroom:prune-archived', function (PruneArchived $prune) {
    $deleted = $prune->handle();

    $this->info("Deleted {$deleted['classes']} archived classes and {$deleted['students']} archived students.");
})->purpose('Delete classes and students archived longer than elifba.retention_days');

Artisan::command('play:abandon-stale', function (AbandonStaleGames $abandon) {
    $this->info("Abandoned {$abandon->handle()} games with no activity.");
})->purpose('Abandon live and paused games idle longer than elifba.abandon_after_hours');

Artisan::command('play:prune-old-games', function (PruneOldGames $prune) {
    $this->info("Deleted {$prune->handle()} games with their rounds and answers.");
})->purpose('Delete games started longer than elifba.retention_days ago');

Artisan::command('play:prune-guest-boards', function (PruneGuestBoards $prune) {
    $this->info("Deleted {$prune->handle()} closed, expired or never-claimed guest boards.");
})->purpose('Delete guest boards that can no longer be used');

// One `scheduler` process runs these (docs/architecture.md §1, §10).
Schedule::command('classroom:prune-archived')->daily();
Schedule::command('play:abandon-stale')->hourly();
Schedule::command('play:prune-old-games')->daily();
Schedule::command('play:prune-guest-boards')->hourly();
