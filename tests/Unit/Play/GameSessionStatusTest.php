<?php

use App\Domain\Play\Enums\GameSessionStatus as Status;

it('allows exactly the lifecycle of the architecture', function (Status $from, array $to): void {
    expect($from->allowedTransitions())->toBe($to);

    foreach (Status::cases() as $status) {
        expect($from->canTransitionTo($status))->toBe(in_array($status, $to, true));
    }
})->with([
    'live' => [Status::Live, [Status::Paused, Status::Finished, Status::Abandoned]],
    'paused' => [Status::Paused, [Status::Live, Status::Finished, Status::Abandoned]],
    'finished' => [Status::Finished, []],
    'abandoned' => [Status::Abandoned, []],
]);

it('calls live and paused open, finished and abandoned final', function (): void {
    expect(Status::open())->toBe([Status::Live, Status::Paused])
        ->and(Status::Finished->isFinal())->toBeTrue()
        ->and(Status::Abandoned->isFinal())->toBeTrue()
        ->and(Status::Live->isFinal())->toBeFalse()
        ->and(Status::Paused->isOpen())->toBeTrue();
});
