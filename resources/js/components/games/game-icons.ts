import {
    Blocks,
    CaseSensitive,
    Hash,
    Image as ImageIcon,
    MousePointerClick,
    Puzzle,
    Text,
    WholeWord,
} from 'lucide-react';
import type { ComponentType } from 'react';
import type { SetupMode, SetupUnit } from '@/types';

type Icon = ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;

/** A shelf's, a chip's and a game page's unit icon (docs/frontend.md rule 13). */
export const UNIT_ICONS: Record<SetupUnit, Icon> = {
    letter: CaseSensitive,
    syllable: Blocks,
    word: WholeWord,
    sentence: Text,
    picture: ImageIcon,
    number: Hash,
};

/** «Asan» taps, «Normal» builds. */
export const MODE_ICONS: Record<SetupMode, Icon> = {
    easy: MousePointerClick,
    normal: Puzzle,
};
