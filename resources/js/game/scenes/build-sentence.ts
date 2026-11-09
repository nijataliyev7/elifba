/**
 * The `build_sentence` scene, «Cümlə qur» (docs/architecture.md §2): the
 * sentence's words are put in order into slots, with its fixed `.`, `?` or
 * `!` (`prompt.tail`) after the last; one tile is a decoy.
 */
import type { SceneOptions } from './board-scene';
import { BuildScene } from './build';
import { BUILD_LAYOUTS } from './layouts';

export class BuildSentenceScene extends BuildScene {
    protected readonly layout = BUILD_LAYOUTS.build_sentence;

    constructor(options: SceneOptions) {
        super('build_sentence', options);
    }
}
