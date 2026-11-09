import { describe, expect, it } from 'vite-plus/test';
import cases from '../../../tests/Fixtures/contract/judge.json';
import midGame from '../../../tests/Fixtures/contract/state.mid-game.json';
import type { Round } from './contract';
import { GameState } from './contract';
import {
    applyHit,
    applySkip,
    boardGame,
    currentRound,
    isOver,
    judge,
    progressOf,
    tally,
} from './engine';

/*
 * The judge cases shared with Pest (tests/Unit/Play/RoundJudgeTest.php):
 * each case plays its hits in order from its starting progress and checks
 * every verdict and the running miss count.
 */
type JudgeCase = {
    name: string;
    items: string[];
    solution: { order: string; item_ids: string[] };
    progress?: string[];
    hits: {
        item_id: string;
        status: string;
        progress: string[];
        misses: number;
        solved: boolean;
    }[];
};

const judgeCases: JudgeCase[] = cases.cases;

function roundOf(testCase: JudgeCase): Pick<Round, 'items' | 'solution'> {
    return {
        items: testCase.items.map((id) => ({
            id,
            text: id,
            image: null,
            content_key: `test:${id}`,
        })),
        solution: {
            order: testCase.solution.order === 'sequence' ? 'sequence' : 'any',
            item_ids: testCase.solution.item_ids,
        },
    };
}

describe('judge', () => {
    it('has the shared cases', () => {
        expect(judgeCases.length).toBeGreaterThanOrEqual(8);
    });

    it.each(judgeCases.map((testCase) => [testCase.name, testCase] as const))(
        '%s',
        (_name, testCase) => {
            const round = roundOf(testCase);
            let progress = testCase.progress ?? [];
            let misses = 0;

            for (const hit of testCase.hits) {
                const verdict = judge(round, progress, hit.item_id);

                if (verdict.status === 'miss') {
                    misses++;
                }

                progress = verdict.progress;

                expect({
                    item_id: hit.item_id,
                    status: verdict.status,
                    progress: verdict.progress,
                    misses,
                    solved: verdict.solved,
                }).toEqual(hit);
            }
        },
    );

    it('never changes the progress it is given', () => {
        const round = roundOf(judgeCases[0]);
        const progress: string[] = [];

        judge(round, progress, round.solution.item_ids[0]);

        expect(progress).toEqual([]);
    });
});

/*
 * The state machine around the judge: where a game stands, the current
 * round, and what a hit or a skip does (docs/architecture.md §4).
 */
describe('the board game', () => {
    const state = GameState.parse(midGame);
    const [first, second] = state.rounds;

    it('resumes at the first round that is neither solved nor skipped', () => {
        const game = boardGame(state);

        expect(currentRound(game)?.id).toBe(second.id);
        expect(progressOf(game, first.id).solved).toBe(true);
        expect(isOver(game)).toBe(false);
    });

    it('treats a round the server has no progress for as new', () => {
        const game = boardGame({ rounds: state.rounds, progress: [] });

        expect(currentRound(game)?.id).toBe(first.id);
        expect(progressOf(game, second.id)).toEqual({
            round_id: second.id,
            hit_item_ids: [],
            misses: 0,
            solved: false,
            skipped: false,
        });
    });

    it('counts a miss and solves the round with the target, without changing the old game', () => {
        const game = boardGame(state);
        const decoy = second.items.find(
            (item) => !second.solution.item_ids.includes(item.id),
        )!.id;
        const target = second.solution.item_ids[0];

        const missed = applyHit(game, second.id, decoy);

        expect(missed.verdict.status).toBe('miss');
        expect(progressOf(missed.game, second.id).misses).toBe(1);
        expect(progressOf(game, second.id).misses).toBe(0);

        const solved = applyHit(missed.game, second.id, target);

        expect(solved.verdict).toEqual({
            status: 'correct',
            progress: [target],
            solved: true,
        });
        expect(progressOf(solved.game, second.id)).toMatchObject({
            hit_item_ids: [target],
            misses: 1,
            solved: true,
        });
        expect(isOver(solved.game)).toBe(true);
        expect(tally(solved.game)).toEqual({
            rounds: 2,
            solved: 2,
            skipped: 0,
            misses: 2,
        });
    });

    it('calls a hit on any round but the current one stale and changes nothing', () => {
        const game = boardGame(state);

        for (const roundId of [first.id, 'no-such-round']) {
            const outcome = applyHit(game, roundId, 'b');

            expect(outcome.verdict.status).toBe('stale');
            expect(outcome.round).toBeNull();
            expect(outcome.game).toBe(game);
        }
    });

    it('skips only the current round', () => {
        const game = boardGame(state);

        expect(applySkip(game, first.id)).toEqual({ game, skipped: false });

        const skipped = applySkip(game, second.id);

        expect(skipped.skipped).toBe(true);
        expect(progressOf(skipped.game, second.id).skipped).toBe(true);
        expect(currentRound(skipped.game)).toBeNull();
        expect(tally(skipped.game).skipped).toBe(1);
    });

    it('keeps the rounds in their index order', () => {
        const game = boardGame({
            rounds: [second, first],
            progress: [],
        });

        expect(game.rounds.map((round) => round.index)).toEqual([0, 1]);
    });
});
