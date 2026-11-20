import type { ComponentType } from 'react';
import { NoStudy } from '@/components/rooms/mechanics/choice';
import {
    NextInSequenceAsk,
    NextInSequenceSolution,
} from '@/components/rooms/mechanics/next-in-sequence';
import {
    OddOneOutAsk,
    OddOneOutSolution,
} from '@/components/rooms/mechanics/odd-one-out';
import {
    AnagramAsk,
    AnagramSolution,
} from '@/components/rooms/mechanics/anagram';
import {
    RecallCellsAsk,
    RecallCellsSolution,
    RecallCellsStudy,
} from '@/components/rooms/mechanics/recall-cells';
import {
    RecallOrderAsk,
    RecallOrderSolution,
    RecallOrderStudy,
} from '@/components/rooms/mechanics/recall-order';
import {
    SpellingAsk,
    SpellingSolution,
} from '@/components/rooms/mechanics/spelling';
import type {
    RoomAsk,
    RoomRaceMechanic,
    RoomMine,
    RoomStudy,
} from '@/rooms/contract';

/**
 * A mechanic's view (architecture §12 "Room games", docs/frontend.md rule
 * 15): its study, its question and its reveal, each at a phone's size or,
 * with `big`, the screen's. A mechanic is a builder on the server, an
 * answer shape for the judge and this; the room page and the screen draw
 * a round through it. The question's taps are the page's (`tap()`); on
 * the screen it is drawn without them. Its items come in, pop and stand
 * out under the `room-motion:` variant, inside a moment the page saw
 * begin (docs/frontend.md rule 15 "Motion", ADR 025).
 */
export type MechanicView = {
    /**
     * `covered` from `answer_from_ms` on the estimated server clock until
     * the question arrives: the material's place with nothing of it shown.
     */
    Study: ComponentType<{
        study: RoomStudy;
        big?: boolean;
        covered?: boolean;
    }>;
    Ask: ComponentType<{
        ask: RoomAsk;
        picked: string[];
        disabled: boolean;
        onPick: (id: string) => void;
        big?: boolean;
    }>;
    Solution: ComponentType<{
        ask: RoomAsk;
        solution: string[];
        mine: RoomMine | null;
        /** A word game's word, from `reveal.word` (B6). */
        word?: string | null;
        big?: boolean;
    }>;
};

export const MECHANIC_VIEWS: Record<RoomRaceMechanic, MechanicView> = {
    recall_order: {
        Study: RecallOrderStudy,
        Ask: RecallOrderAsk,
        Solution: RecallOrderSolution,
    },
    recall_cells: {
        Study: RecallCellsStudy,
        Ask: RecallCellsAsk,
        Solution: RecallCellsSolution,
    },
    // B5: nothing is studied; the question opens the round.
    next_in_sequence: {
        Study: NoStudy,
        Ask: NextInSequenceAsk,
        Solution: NextInSequenceSolution,
    },
    odd_one_out: {
        Study: NoStudy,
        Ask: OddOneOutAsk,
        Solution: OddOneOutSolution,
    },
    // B6: nothing is studied either.
    anagram: {
        Study: NoStudy,
        Ask: AnagramAsk,
        Solution: AnagramSolution,
    },
    spelling: {
        Study: NoStudy,
        Ask: SpellingAsk,
        Solution: SpellingSolution,
    },
};
