/**
 * The board's game logic without Phaser (docs/architecture.md §4): the
 * judge and the pure state machine around it — the rounds, where each
 * stands, the current round, and what a hit or a skip does to them. Nothing
 * here touches the DOM, the network or a clock, so Vitest runs it as is.
 *
 * The judge — "is this item the next correct one?" — is the only rule both
 * sides implement. `Play\Support\RoundJudge` in PHP is authoritative and
 * decides what is stored; this mirror gives instant feedback. Both run every
 * case of `tests/Fixtures/contract/judge.json`. Points exist only on the
 * server.
 */
import type { GameState, Round, RoundProgress } from './contract';

/** What a hit is on the board: `duplicate` and `skipped` come from elsewhere. */
export type Verdict = {
    status: 'correct' | 'miss' | 'stale';
    /** Item ids hit correctly so far, in order, with this hit when correct. */
    progress: string[];
    solved: boolean;
};

type Judged = Pick<Round, 'items' | 'solution'>;

export function isSolved(
    solution: Round['solution'],
    progress: readonly string[],
): boolean {
    return solution.order === 'any'
        ? solution.item_ids.every((itemId) => progress.includes(itemId))
        : progress.length >= solution.item_ids.length;
}

/**
 * - `any` (find): an item of the solution not hit yet is correct and
 *   bursts; a burst item hit again is `stale`.
 * - `sequence` (build): only `solution.item_ids[progress.length]` is
 *   correct; tiles are reusable, so the same item may be correct again.
 * - Anything else in the round is a miss. An item not in the round, or any
 *   hit on a solved round, is `stale`.
 */
export function judge(
    round: Judged,
    progress: readonly string[],
    itemId: string,
): Verdict {
    const { solution } = round;
    const stale = (): Verdict => ({
        status: 'stale',
        progress: [...progress],
        solved: isSolved(solution, progress),
    });

    if (
        isSolved(solution, progress) ||
        !round.items.some((item) => item.id === itemId)
    ) {
        return stale();
    }

    let correct: boolean;

    if (solution.order === 'any') {
        if (progress.includes(itemId)) {
            return stale();
        }

        correct = solution.item_ids.includes(itemId);
    } else {
        correct = solution.item_ids[progress.length] === itemId;
    }

    if (!correct) {
        return { status: 'miss', progress: [...progress], solved: false };
    }

    const next = [...progress, itemId];

    return {
        status: 'correct',
        progress: next,
        solved: isSolved(solution, next),
    };
}

/**
 * A game as the board holds it: every round snapshot in order and where
 * each stands, keyed by round id. Values are never mutated; every change
 * returns a new game.
 */
export type BoardGame = {
    readonly rounds: readonly Round[];
    readonly progress: Readonly<Record<string, RoundProgress>>;
};

/** What a hit did: the game after it and the judge's verdict. */
export type HitOutcome = {
    game: BoardGame;
    verdict: Verdict;
    /** The round the hit was judged against; null when it was stale. */
    round: Round | null;
};

/** What a skip did: the game after it, and whether a round was skipped. */
export type SkipOutcome = { game: BoardGame; skipped: boolean };

export type Tally = {
    rounds: number;
    solved: number;
    skipped: number;
    misses: number;
};

function emptyProgress(roundId: string): RoundProgress {
    return {
        round_id: roundId,
        hit_item_ids: [],
        misses: 0,
        solved: false,
        skipped: false,
    };
}

/**
 * The game from `GET /games/{game}/state`: the rounds in their order and
 * the server's progress; a round the server has no progress for is new.
 */
export function boardGame(
    state: Pick<GameState, 'rounds' | 'progress'>,
): BoardGame {
    const rounds = [...state.rounds].sort((a, b) => a.index - b.index);
    const known = new Map(state.progress.map((p) => [p.round_id, p]));
    const progress: Record<string, RoundProgress> = {};

    for (const round of rounds) {
        progress[round.id] = known.get(round.id) ?? emptyProgress(round.id);
    }

    return { rounds, progress };
}

export function isOpen(progress: RoundProgress): boolean {
    return !progress.solved && !progress.skipped;
}

export function progressOf(game: BoardGame, roundId: string): RoundProgress {
    return game.progress[roundId] ?? emptyProgress(roundId);
}

/** The first round that is neither solved nor skipped: where play resumes. */
export function currentRound(game: BoardGame): Round | null {
    return (
        game.rounds.find((round) => isOpen(progressOf(game, round.id))) ?? null
    );
}

/** Every round is solved or skipped. */
export function isOver(game: BoardGame): boolean {
    return currentRound(game) === null;
}

function withProgress(game: BoardGame, progress: RoundProgress): BoardGame {
    return {
        rounds: game.rounds,
        progress: { ...game.progress, [progress.round_id]: progress },
    };
}

/**
 * A hit on an item of the current round. A hit naming another round (one
 * already over, or one not reached yet) is `stale` and changes nothing, as
 * is anything the judge calls stale. A correct hit adds the item to the
 * round's progress and may solve it; a miss counts.
 */
export function applyHit(
    game: BoardGame,
    roundId: string,
    itemId: string,
): HitOutcome {
    const round = currentRound(game);

    if (round === null || round.id !== roundId) {
        const progress = progressOf(game, roundId);

        return {
            game,
            round: null,
            verdict: {
                status: 'stale',
                progress: [...progress.hit_item_ids],
                solved: progress.solved,
            },
        };
    }

    const before = progressOf(game, round.id);
    const verdict = judge(round, before.hit_item_ids, itemId);

    switch (verdict.status) {
        case 'correct':
            return {
                round,
                verdict,
                game: withProgress(game, {
                    ...before,
                    hit_item_ids: verdict.progress,
                    solved: verdict.solved,
                }),
            };
        case 'miss':
            return {
                round,
                verdict,
                game: withProgress(game, {
                    ...before,
                    misses: before.misses + 1,
                }),
            };
        default:
            return { round, verdict, game };
    }
}

/** The teacher passes on the current round; any other round is left alone. */
export function applySkip(game: BoardGame, roundId: string): SkipOutcome {
    const round = currentRound(game);

    if (round === null || round.id !== roundId) {
        return { game, skipped: false };
    }

    return {
        skipped: true,
        game: withProgress(game, {
            ...progressOf(game, roundId),
            skipped: true,
        }),
    };
}

export function tally(game: BoardGame): Tally {
    const all = game.rounds.map((round) => progressOf(game, round.id));

    return {
        rounds: game.rounds.length,
        solved: all.filter((p) => p.solved).length,
        skipped: all.filter((p) => p.skipped).length,
        misses: all.reduce((sum, p) => sum + p.misses, 0),
    };
}
