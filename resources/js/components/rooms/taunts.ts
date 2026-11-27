import { throwRows } from '@/components/rooms/rivalry';
import type { RoomState } from '@/rooms/contract';
import type { StorageLike } from '@/rooms/sound';

/*
 * Taunts on a page (docs/frontend.md rule 15 "Taunts", ADR 029 decisions
 * 36–43 as amended 2026-11-19, T3), the part that is pure: which taunts a
 * page draws now as reactions in the middle of the viewport, which of them
 * it saw arrive and how they pop, what the strip's throw button and its
 * fan say, and the screen's choice to hide them (a phone has none: only
 * the host turns taunts off, ADR 029 decision 55). Everything else is the
 * server's: whether a throw is open, the balance, the cooldown's end and
 * which taunts a state holds. Nothing here reads a verdict, a solution or
 * anybody's result — only the state's `taunts`, the round's index and
 * phase, and the strip's rival.
 */

/**
 * How long a state lists a taunt after its throw, by the server's clock:
 * the server's `elifba.rooms.taunts.show_ms` (`RoomTauntsTest` holds the
 * two equal).
 */
export const TAUNT_SHOW_MS = 4_000;

/**
 * How long a reaction is drawn after its throw, by the server's clock
 * (ADR 029 decision 41): less than `TAUNT_SHOW_MS`, so a state read a
 * second late still holds it (`RoomTauntsTest` holds it below `show_ms`).
 */
export const TAUNT_REACTION_MS = 3_000;

/** How long «Atıldı → :name · −1 xal» lies over the strip after an accepted throw. */
export const TAUNT_SENT_MS = 2_000;

/** At most two reactions at once, one above the other; a third waits for a place. */
export const TAUNT_REACTIONS = 2;

/** Two reactions that arrive together pop this far apart («Partlayış», decision 43). */
export const TAUNT_POP_STAGGER_MS = 260;

/** The shortest a pop lasts, however late it arrived: its growth and its fade. */
export const TAUNT_POP_MIN_MS = 600;

/**
 * The screen's «Atmacalar» toggle, like its mute: never sent. A phone has
 * no such choice (ADR 029 decision 55); a phone's old
 * `elifba.rooms.phone.taunts_hidden` is never read.
 */
export const TAUNTS_HIDDEN_KEY = 'elifba.rooms.screen.taunts_hidden';

/** Whether the screen hides taunts; false when storage cannot say. */
export function readTauntsHidden(storage: StorageLike | null): boolean {
    try {
        return storage?.getItem(TAUNTS_HIDDEN_KEY) === '1';
    } catch {
        return false;
    }
}

/** Remembers the screen's choice; a refusal only means it is not remembered. */
export function writeTauntsHidden(
    storage: StorageLike | null,
    hidden: boolean,
): void {
    try {
        if (hidden) {
            storage?.setItem(TAUNTS_HIDDEN_KEY, '1');
        } else {
            storage?.removeItem(TAUNTS_HIDDEN_KEY);
        }
    } catch {
        // Not remembered: the choice holds until the page is left.
    }
}

/**
 * The reactions a page draws at `nowMs` (the server's clock as the page
 * estimates it): the state's taunts, each until `sent_ms +
 * TAUNT_REACTION_MS`, the two that came first, older first — a third waits
 * for one of them to go — and none while this device hides them or before
 * the page knows the server's clock.
 */
export function shownTaunts<T extends { id: string; sent_ms: number }>(
    list: readonly T[] | null | undefined,
    nowMs: number | null,
    hidden: boolean,
): T[] {
    if (hidden || nowMs === null || list === null || list === undefined) {
        return [];
    }

    return list
        .filter((taunt) => taunt.sent_ms + TAUNT_REACTION_MS > nowMs)
        .sort((a, b) => a.sent_ms - b.sent_ms || (a.id < b.id ? -1 : 1))
        .slice(0, TAUNT_REACTIONS);
}

/** The latest moment any of these taunts is still drawn as a reaction, or null. */
export function tauntsEnd(
    list: readonly { sent_ms: number }[] | null | undefined,
): number | null {
    if (list === null || list === undefined || list.length === 0) {
        return null;
    }

    return Math.max(...list.map((taunt) => taunt.sent_ms + TAUNT_REACTION_MS));
}

/** The taunts a page has drawn, by id, and whether it has drawn its first state. */
export type TauntsSeen = { opened: boolean; ids: ReadonlySet<string> };

export const TAUNTS_UNSEEN: TauntsSeen = { opened: false, ids: new Set() };

/**
 * Which of a state's taunts the page saw arrive (ADR 029 decisions 24 and
 * 43): one
 * in the first state the page drew was there before the page — it is drawn
 * still for the time it has left; any other arrives the first time a state
 * holds it; a state fetched again brings nothing new.
 */
export function tauntMoment(
    seen: TauntsSeen,
    ids: readonly string[],
): { seen: TauntsSeen; arrived: string[] } {
    const known = new Set(seen.ids);
    const arrived = seen.opened ? ids.filter((id) => !known.has(id)) : [];

    ids.forEach((id) => known.add(id));

    return { seen: { opened: true, ids: known }, arrived };
}

/** How a reaction the page saw arrive pops: its whole motion's length and its delay, in ms. */
export type TauntPop = { duration: number; delay: number };

/**
 * «Partlayış» (ADR 029 decision 43) for the reactions the page saw arrive
 * and draws for the first time now (`fresh`, older first): each grows,
 * holds and floats away within the time it has left until `sent_ms +
 * TAUNT_REACTION_MS`, and two drawn together start `TAUNT_POP_STAGGER_MS`
 * apart. Its length is fixed here, once, so a later read of the clock
 * never restarts or stretches a motion under way.
 */
export function tauntPops(
    fresh: readonly { id: string; sent_ms: number }[],
    nowMs: number,
): Map<string, TauntPop> {
    return new Map(
        fresh.map((taunt, index) => {
            const delay = index * TAUNT_POP_STAGGER_MS;
            const left = taunt.sent_ms + TAUNT_REACTION_MS - nowMs - delay;

            return [
                taunt.id,
                { duration: Math.max(TAUNT_POP_MIN_MS, left), delay },
            ];
        }),
    );
}

/** Why the tray cannot throw now; null when it can. */
export type TrayReason = 'first_round' | 'no_points' | 'cooling';

export type TrayView =
    | { kind: 'none' }
    | {
          kind: 'tray';
          balance: number;
          reason: TrayReason | null;
          /** Whole seconds to the end of the cooldown while it runs, by the server's clock. */
          cooling: number | null;
      };

/**
 * What the strip's throw button and its fan show (ADR 029 decisions 4, 36
 * and 37; `tray` is T2's name for them):
 * nothing unless the viewer plays, a throw is open (`taunts.open`: the
 * host's switch on, before the last close) and the strip has a rival to
 * throw at; else the viewer's balance, and why a throw would not be taken
 * now — before the first close, no points, or the cooldown with its
 * seconds. It reads the state's `taunts` and the round's index and phase,
 * never an answer, a result or the ranking.
 */
export function trayView(
    state: RoomState,
    rival: string | null,
    nowMs: number | null,
): TrayView {
    const taunts = state.taunts;

    if (
        taunts === null ||
        taunts.balance === null ||
        !taunts.open ||
        rival === null
    ) {
        return { kind: 'none' };
    }

    const left =
        taunts.ready_ms === null || nowMs === null
            ? 0
            : taunts.ready_ms - nowMs;
    const cooling = left > 0 ? Math.ceil(left / 1000) : null;
    const beforeFirstClose =
        state.round !== null &&
        state.round.index === 0 &&
        state.round.phase !== 'reveal';
    let reason: TrayReason | null = null;

    if (taunts.balance < 1) {
        reason = beforeFirstClose ? 'first_round' : 'no_points';
    } else if (cooling !== null) {
        reason = 'cooling';
    }

    return { kind: 'tray', balance: taunts.balance, reason, cooling };
}

/** Someone a throw may go to: their row's id and the state's name (null: «Oyunçu»). */
export type TauntTarget = { id: string; name: string | null };

/** Whom the fan may throw at, and at whom a tile throws now (`throwChoice()`). */
export type ThrowChoice = {
    /** Whom a throw may go to: a race's in the state's ranking order, never re-sorted. */
    targets: TauntTarget[];
    /** Whom a tile throws at now; null with nobody to aim at. */
    target: TauntTarget | null;
    /** Two or more: the fan opens with the row of names. */
    row: boolean;
};

/**
 * Whom the thrower may pick (ADR 029 as amended 2026-11-21, decisions
 * 60–62): exactly whom the server takes, read from the state through the
 * strip's rows (`throwRows()` beside `stripRows()`, the one place the
 * strip looks at the game's kind). In a race those are the state's ranking, so every present player
 * but the viewer — never oneself, a player who left, a removed one or a
 * host who does not play — in the ranking's order as the server gave it;
 * in a match the two seats, so the other seat alone, and nobody for a
 * watcher (ADR 034 decision 22) — nor against the computer, whose seat is
 * no player's and never present (ADR 036 decision 13); in a domino game the
 * present players who sit on another side than the viewer's, in the order
 * of the seats — never a partner, a computer's seat or a watcher, and
 * nobody for a viewer who does not sit (ADR 038 decision 32). Nobody while
 * the strip shows no rival, since the button is the rival's side (decision
 * 36).
 * The tile throws at
 * `picked` while it may still be picked, else at the strip's rival, else
 * at the first of the row: a picked player who left drops out before any
 * throw. It reads no answer, result or verdict.
 */
export function throwChoice(
    state: Pick<RoomState, 'me' | 'players' | 'ranking' | 'match'> & {
        domino?: RoomState['domino'];
    },
    rival: TauntTarget | null,
    picked: string | null,
): ThrowChoice {
    const me = state.me.player_id;
    const present = new Map(
        state.players
            .filter((player) => player.present)
            .map((player) => [player.id, player.name]),
    );
    const targets =
        me === null || rival === null
            ? []
            : throwRows(state)
                  .filter((id) => id !== me && present.has(id))
                  .map((id) => ({ id, name: present.get(id) ?? null }));
    const target =
        targets.find((one) => one.id === picked) ??
        targets.find((one) => one.id === rival?.id) ??
        targets[0] ??
        null;

    return { targets, target, row: targets.length > 1 };
}
