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
    RoomLetters,
    RoomRaceMechanic,
    RoomMine,
    RoomStudy,
} from '@/rooms/contract';

/**
 * What a question's own row of tools works with, for a view that draws
 * one (`drawsTools`): given only to a player who may still answer, in the
 * answer phase, and never on the screen. «Anaqram» (ADR 037) draws
 * «Qarışdır», «Hərf al» and «Geri» from it.
 */
export type AskTools = {
    /** «Geri»: the viewer's last own tap taken back — never a bought letter; null with none to take back. */
    undo: (() => void) | null;
    /** «Hərf al»: buys the next letter; the page then waits for its state. */
    buy: () => void;
    /** A purchase is on its way and its state has not come yet. */
    buying: boolean;
    /** A tap of a tool that sounds like a tap of an item. */
    tapped: () => void;
};

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
        /** The answer being made, in order — from ADR 037 the bought letters first. */
        picked: string[];
        disabled: boolean;
        onPick: (id: string) => void;
        big?: boolean;
        /**
         * The viewer's bought letters (`round.letters`, ADR 037): the tiles
         * locked in the word's first places, the viewer's own orders and
         * the next letter's price. Null in a round that sells none, for a
         * viewer who does not play and on the screen.
         */
        letters?: RoomLetters | null;
        /** The question's own tools, for a view that `drawsTools`; null when none may be used. */
        tools?: AskTools | null;
    }>;
    Solution: ComponentType<{
        ask: RoomAsk;
        solution: string[];
        mine: RoomMine | null;
        /** A word game's word, from `reveal.word` (B6). */
        word?: string | null;
        big?: boolean;
    }>;
    /**
     * The question draws its own row of tools, «Geri» among them, so the
     * round does not draw its «Geri» (ADR 037: «Anaqram»).
     */
    drawsTools?: true;
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
        drawsTools: true,
    },
    spelling: {
        Study: NoStudy,
        Ask: SpellingAsk,
        Solution: SpellingSolution,
    },
};
