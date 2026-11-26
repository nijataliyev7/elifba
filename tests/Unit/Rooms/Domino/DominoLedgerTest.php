<?php

use App\Domain\Rooms\Enums\RoomHandEnding;
use App\Domain\Rooms\Enums\RoomLedgerLine;
use App\Domain\Rooms\Support\Domino\Domino101;
use App\Domain\Rooms\Support\Domino\DominoLedger;

/*
 * The account of «101» between hands (docs/architecture.md §12 "Domino",
 * ADR 038 R20–R26): 13 opens an account and takes its waiting points with
 * it; a smaller win waits and burns at the third try; an open account's
 * win burns the closed ones' waiting points; the bank goes to a winner
 * whose account is or becomes open and burns otherwise; the game ends at
 * 101, dry when every other side stands at 0.
 */

function dominoLedger(int $sides = 2): DominoLedger
{
    return (new Domino101)->ledger($sides);
}

/**
 * @return array{open: bool, balance: int, waiting: int, tries: int}
 */
function dominoAccount(bool $open = false, int $balance = 0, int $waiting = 0, int $tries = 0): array
{
    return ['open' => $open, 'balance' => $balance, 'waiting' => $waiting, 'tries' => $tries];
}

/**
 * @return array{side: int|null, line: RoomLedgerLine, points: int}
 */
function dominoLine(?int $side, RoomLedgerLine $line, int $points): array
{
    return ['side' => $side, 'line' => $line, 'points' => $points];
}

it('begins every account closed, at 0, nothing waiting and no tries, and the bank empty (ADR 038 R20)', function (): void {
    $ledger = dominoLedger(3);

    expect($ledger->sides)->toBe([dominoAccount(), dominoAccount(), dominoAccount()])
        ->and($ledger->bank)->toBe(0)
        ->and($ledger->lines)->toBe([])
        ->and($ledger->anyOpen())->toBeFalse()
        ->and($ledger->winner())->toBeNull()
        ->and($ledger->dry())->toBeFalse();
});

it('adds an open account\'s gain to its balance (ADR 038 R20)', function (): void {
    $ledger = dominoLedger()->win(0, 20)->win(0, 7);

    expect($ledger->sides[0])->toBe(dominoAccount(open: true, balance: 27))
        ->and($ledger->lines)->toBe([dominoLine(0, RoomLedgerLine::Gained, 7)]);
});

it('opens an account with a gain of 13 or more, and not with 12 (ADR 038 R21)', function (): void {
    $thirteen = dominoLedger()->win(1, 13);
    $twelve = dominoLedger()->win(1, 12);

    expect($thirteen->sides[1])->toBe(dominoAccount(open: true, balance: 13))
        ->and($thirteen->lines)->toBe([dominoLine(1, RoomLedgerLine::Opened, 13)])
        ->and($thirteen->anyOpen())->toBeTrue()
        ->and($twelve->sides[1])->toBe(dominoAccount(waiting: 12, tries: 1))
        ->and($twelve->lines)->toBe([dominoLine(1, RoomLedgerLine::Waited, 12)])
        ->and($twelve->anyOpen())->toBeFalse();
});

it('takes the waiting points into the balance at the opening (ADR 038 R21)', function (): void {
    $ledger = dominoLedger()->win(0, 12)->win(0, 20);

    expect($ledger->sides[0])->toBe(dominoAccount(open: true, balance: 32))
        ->and($ledger->lines)->toBe([
            dominoLine(0, RoomLedgerLine::Opened, 20),
            dominoLine(0, RoomLedgerLine::Released, 12),
        ]);
});

it('counts only the hand\'s gain toward the 13, never the waiting points (ADR 038 R21)', function (): void {
    $ledger = dominoLedger()->win(0, 8)->win(0, 9);

    expect($ledger->sides[0])->toBe(dominoAccount(waiting: 17, tries: 2));
});

it('burns the waiting points at the third small win, the third gain with them (ADR 038 R22)', function (): void {
    $ledger = dominoLedger()->win(0, 5)->win(0, 4)->win(0, 3);

    expect($ledger->sides[0])->toBe(dominoAccount())
        ->and($ledger->lines)->toBe([
            dominoLine(0, RoomLedgerLine::Waited, 3),
            dominoLine(0, RoomLedgerLine::BurntThird, 12),
        ]);
});

it('starts the tries again after the burn (ADR 038 R22, reading)', function (): void {
    $ledger = dominoLedger()->win(0, 5)->win(0, 4)->win(0, 3)->win(0, 6)->win(0, 2);

    expect($ledger->sides[0])->toBe(dominoAccount(waiting: 8, tries: 2));

    $burnt = $ledger->win(0, 1);

    expect($burnt->sides[0])->toBe(dominoAccount())
        ->and($burnt->lines[1])->toBe(dominoLine(0, RoomLedgerLine::BurntThird, 9));
});

it('changes no count of tries for another side\'s win in between (ADR 038 R22)', function (): void {
    $ledger = dominoLedger()->win(0, 5)->win(1, 4)->win(0, 6)->win(1, 3);

    expect($ledger->sides)->toBe([dominoAccount(waiting: 11, tries: 2), dominoAccount(waiting: 7, tries: 2)]);

    $third = $ledger->win(0, 2);

    expect($third->sides)->toBe([dominoAccount(), dominoAccount(waiting: 7, tries: 2)]);
});

it('burns the waiting points of every closed account at an open account\'s win, the opening included (ADR 038 R23)', function (): void {
    $waiting = dominoLedger(3)->win(1, 8)->win(2, 5);
    $opening = $waiting->win(0, 20);

    expect($opening->sides)->toBe([dominoAccount(open: true, balance: 20), dominoAccount(tries: 1), dominoAccount(tries: 1)])
        ->and($opening->lines)->toBe([
            dominoLine(0, RoomLedgerLine::Opened, 20),
            dominoLine(1, RoomLedgerLine::BurntRival, 8),
            dominoLine(2, RoomLedgerLine::BurntRival, 5),
        ]);

    $again = $opening->win(1, 6)->win(0, 3);

    expect($again->sides)->toBe([dominoAccount(open: true, balance: 23), dominoAccount(tries: 2), dominoAccount(tries: 1)])
        ->and($again->lines)->toBe([
            dominoLine(0, RoomLedgerLine::Gained, 3),
            dominoLine(1, RoomLedgerLine::BurntRival, 6),
        ]);
});

it('burns nobody\'s waiting points at the win of an account that stays closed (ADR 038 R23)', function (): void {
    $ledger = dominoLedger()->win(1, 8)->win(0, 5);

    expect($ledger->sides)->toBe([dominoAccount(waiting: 5, tries: 1), dominoAccount(waiting: 8, tries: 1)])
        ->and($ledger->lines)->toBe([dominoLine(0, RoomLedgerLine::Waited, 5)]);
});

it('adds a tied hand\'s pips to the bank and changes no account (ADR 038 R19, R24)', function (): void {
    $ledger = dominoLedger()->win(0, 5)->after(RoomHandEnding::Tied, null, 24)->tie(30);

    expect($ledger->bank)->toBe(54)
        ->and($ledger->sides)->toBe([dominoAccount(waiting: 5, tries: 1), dominoAccount()])
        ->and($ledger->lines)->toBe([dominoLine(null, RoomLedgerLine::BankAdded, 30)]);
});

it('gives the bank to the next winner whose account is open (ADR 038 R24)', function (): void {
    $ledger = dominoLedger()->win(0, 20)->tie(24)->win(0, 5);

    expect($ledger->bank)->toBe(0)
        ->and($ledger->sides[0])->toBe(dominoAccount(open: true, balance: 49))
        ->and($ledger->lines)->toBe([
            dominoLine(0, RoomLedgerLine::Gained, 5),
            dominoLine(0, RoomLedgerLine::BankTaken, 24),
        ]);
});

it('gives the bank to a winner whose account opens in that hand (ADR 038 R24)', function (): void {
    $ledger = dominoLedger()->win(1, 6)->tie(24)->win(1, 15);

    expect($ledger->bank)->toBe(0)
        ->and($ledger->sides[1])->toBe(dominoAccount(open: true, balance: 45))
        ->and($ledger->lines)->toBe([
            dominoLine(1, RoomLedgerLine::Opened, 15),
            dominoLine(1, RoomLedgerLine::Released, 6),
            dominoLine(1, RoomLedgerLine::BankTaken, 24),
        ]);
});

it('burns the bank with a winner whose account stays closed, never counting it toward the 13 (ADR 038 R24)', function (): void {
    $ledger = dominoLedger()->tie(40)->win(0, 10);

    expect($ledger->bank)->toBe(0)
        ->and($ledger->sides[0])->toBe(dominoAccount(waiting: 10, tries: 1))
        ->and($ledger->lines)->toBe([
            dominoLine(0, RoomLedgerLine::Waited, 10),
            dominoLine(null, RoomLedgerLine::BankBurnt, 40),
        ]);
});

it('keeps the bank through a void hand, which changes nothing (ADR 038 R5, R24)', function (): void {
    $ledger = dominoLedger()->tie(12)->after(RoomHandEnding::Void, null, 0);

    expect($ledger->bank)->toBe(12)
        ->and($ledger->sides)->toBe([dominoAccount(), dominoAccount()])
        ->and($ledger->lines)->toBe([]);
});

it('ends the game when a balance is 101 or more, by a blocked hand too (ADR 038 R25)', function (): void {
    $ledger = dominoLedger()->win(0, 60)->win(1, 13)->after(RoomHandEnding::Blocked, 0, 40);

    expect($ledger->sides[0]['balance'])->toBe(100)
        ->and($ledger->winner())->toBeNull()
        ->and($ledger->toTarget(0))->toBe(1)
        ->and($ledger->after(RoomHandEnding::Blocked, 0, 1)->winner())->toBe(0)
        ->and($ledger->after(RoomHandEnding::Finished, 0, 30)->winner())->toBe(0)
        ->and($ledger->after(RoomHandEnding::Finished, 0, 30)->toTarget(0))->toBe(0);
});

it('counts neither waiting points nor the bank in a balance (ADR 038 R25)', function (): void {
    $waiting = dominoLedger()->win(0, 12)->win(0, 12);
    $bank = dominoLedger()->tie(150);

    expect($waiting->winner())->toBeNull()
        ->and($bank->winner())->toBeNull()
        ->and(dominoLedger()->win(1, 95)->tie(10)->win(1, 1)->winner())->toBe(1);
});

it('calls a win dry when every other side\'s balance is 0 (ADR 038 R26)', function (): void {
    $dry = dominoLedger()->win(0, 60)->win(1, 12)->win(0, 41);
    $wet = dominoLedger()->win(1, 13)->win(0, 60)->win(0, 41);

    expect($dry->winner())->toBe(0)
        ->and($dry->sides[1])->toBe(dominoAccount(tries: 1))
        ->and($dry->dry())->toBeTrue()
        ->and($wet->winner())->toBe(0)
        ->and($wet->dry())->toBeFalse();
});

it('calls a win dry at three and four sides only when every other side is at 0 (ADR 038 R26, reading)', function (): void {
    $dry = dominoLedger(4)->win(2, 70)->win(1, 5)->win(2, 31);
    $wet = dominoLedger(3)->win(2, 70)->win(1, 13)->win(2, 31);

    expect($dry->winner())->toBe(2)
        ->and($dry->dry())->toBeTrue()
        ->and($wet->winner())->toBe(2)
        ->and($wet->dry())->toBeFalse();
});

it('orders the sides by balance, then by side, with dense places (ADR 038 decision 38)', function (): void {
    $ledger = dominoLedger(4)->win(2, 20)->win(0, 15)->win(3, 20);

    expect($ledger->standing())->toBe([
        ['side' => 2, 'place' => 1],
        ['side' => 3, 'place' => 1],
        ['side' => 0, 'place' => 2],
        ['side' => 1, 'place' => 3],
    ]);
});
