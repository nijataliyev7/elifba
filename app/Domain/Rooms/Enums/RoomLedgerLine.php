<?php

namespace App\Domain\Rooms\Enums;

/**
 * One change of a domino game's account (docs/architecture.md §12
 * "Domino", ADR 038 decision 38, the state's `sheet.lines`), named by the
 * server so that a page writes a sentence and computes nothing. Folded by
 * `DominoLedger` (R20–R26).
 */
enum RoomLedgerLine: string
{
    /** An open account's win goes to its balance (R20). */
    case Gained = 'gained';

    /** A closed account's win of 13 or more opens it and goes to its balance (R21). */
    case Opened = 'opened';

    /** A closed account's win under 13 waits (R22). */
    case Waited = 'waited';

    /** At the opening the waiting points go to the balance too (R21). */
    case Released = 'released';

    /** An open account's win burns a closed account's waiting points (R23). */
    case BurntRival = 'burnt_rival';

    /** A closed account's third small win burns its waiting points, that win's with them (R22). */
    case BurntThird = 'burnt_third';

    /** A tied hand's pips go to the bank (R19, R24). */
    case BankAdded = 'bank_added';

    /** The bank goes to a winner whose account is or becomes open (R24). */
    case BankTaken = 'bank_taken';

    /** The bank burns with a winner whose account stays closed (R24). */
    case BankBurnt = 'bank_burnt';
}
