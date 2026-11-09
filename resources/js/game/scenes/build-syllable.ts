/**
 * The `build_syllable` scene, «Heca qur» (docs/architecture.md §2): the
 * syllable is the prompt above the canvas (`prompt.show`); its letters are
 * built into slots from letter tiles, a letter that comes twice (`tut`)
 * from the same tile.
 */
import type { SceneOptions } from './board-scene';
import { BuildScene } from './build';
import { BUILD_LAYOUTS } from './layouts';

export class BuildSyllableScene extends BuildScene {
    protected readonly layout = BUILD_LAYOUTS.build_syllable;

    constructor(options: SceneOptions) {
        super('build_syllable', options);
    }
}
