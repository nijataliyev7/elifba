/**
 * The canvas of the board (docs/architecture.md §4, "Board in the
 * browser"). Only the board page loads this module, with a dynamic
 * `import()`, so Phaser lands in the board's own chunk and no other page
 * downloads it.
 *
 * Phaser draws at a logical 1920×1080 scaled with `Scale.FIT` into the
 * element React gives it. Andika is loaded before the first text exists:
 * canvas text does not make the browser fetch a font, and Ə, İ or ğ would
 * otherwise be drawn in a fallback face.
 */
import Phaser from 'phaser';
import type { BoardBridge } from './bridge';
import type { Mechanic } from './contract';
import type {
    BoardScene,
    BoardSounds,
    SceneOptions,
} from './scenes/board-scene';
import { BOARD_HEIGHT, BOARD_WIDTH } from './scenes/board-scene';
import { BuildSentenceScene } from './scenes/build-sentence';
import { BuildSyllableScene } from './scenes/build-syllable';
import { BuildWordScene } from './scenes/build-word';
import { CountScene } from './scenes/count';
import type { Figure, Pictures } from './figures';
import { FindScene } from './scenes/find';
import { builds } from './scenes/layouts';
import { itemStyle, slotStyle, styleOfUnit } from './styles/registry';
import type { BoardVoice } from './voice';

/**
 * One Phaser scene per mechanic (architecture §4). A `pop` round is a find
 * round whose solution is every item, so the find scene draws it as is.
 */
const SCENES: Record<Mechanic, new (options: SceneOptions) => BoardScene> = {
    find: FindScene,
    pop: FindScene,
    build_syllable: BuildSyllableScene,
    build_word: BuildWordScene,
    build_sentence: BuildSentenceScene,
    count: CountScene,
};

export type StageOptions = {
    parent: HTMLElement;
    bridge: BoardBridge;
    mechanic: Mechanic;
    /** The pack's item style key (`pack.style.item`). */
    itemStyle: string;
    /** The pack's slot style key (`pack.style.slot`), drawn by the build scenes. */
    slotStyle: string;
    /** The pack palette, `#RRGGBB` strings from the props. */
    palette: string[];
    sounds: BoardSounds;
    /** What the board says (props `voice`, §3 "Voice"); played on the page's one AudioContext. */
    voice: BoardVoice;
    /** The pack's figures (`pack.style.figures`), drawn by `animal` and `fruit` items. */
    figures: Figure[];
    /** The pictures that show a content key (`pack.pictures`), for the same items. */
    pictures: Pictures;
};

export type Stage = {
    /** Resumes audio inside a user gesture («Başla»). */
    unlockAudio: () => void;
    destroy: () => void;
};

let audioContext: AudioContext | undefined;

/**
 * One AudioContext for the page, given to every stage. Phaser closes a
 * context it made itself when its game is destroyed, but its "visible"
 * handler touches the context 100 ms later, so a stage destroyed while the
 * page stays (the guest board's next game, or a board closed from the
 * phone) threw "Cannot suspend a closed AudioContext". A context Phaser
 * was given is only suspended, and the next stage resumes it on «Başla».
 */
function sharedAudioContext(): AudioContext | undefined {
    if (audioContext === undefined && typeof AudioContext !== 'undefined') {
        audioContext = new AudioContext();
    }

    return audioContext;
}

function colour(hex: string): number | null {
    return /^#[0-9a-f]{6}$/i.test(hex) ? parseInt(hex.slice(1), 16) : null;
}

export async function mountStage(options: StageOptions): Promise<Stage> {
    const style = itemStyle(options.itemStyle);
    const slots = slotStyle(options.slotStyle);

    // Playable lets no pack start whose styles are not drawn here.
    if (style === null) {
        throw new Error(`The board cannot draw ${options.itemStyle} items.`);
    }

    if (builds(options.mechanic) && slots === null) {
        throw new Error(`The board cannot draw ${options.slotStyle} slots.`);
    }

    const Scene = SCENES[options.mechanic];

    await Promise.all([
        document.fonts.load('700 64px Andika', 'ƏİĞŞÇÖÜəığşçöü'),
        document.fonts.load('400 32px Andika', 'Əə'),
    ]);

    const palette = options.palette
        .map(colour)
        .filter((value): value is number => value !== null);

    return new Promise<Stage>((resolve) => {
        const game = new Phaser.Game({
            type: Phaser.AUTO,
            parent: options.parent,
            // The page behind shows through: its colour, or the pack's
            // backdrop over the whole screen (`board-screen.tsx`, §8).
            transparent: true,
            banner: false,
            disableContextMenu: true,
            scale: {
                mode: Phaser.Scale.FIT,
                autoCenter: Phaser.Scale.CENTER_BOTH,
                width: BOARD_WIDTH,
                height: BOARD_HEIGHT,
            },
            input: { activePointers: 4 },
            audio: { context: sharedAudioContext() },
            scene: new Scene({
                bridge: options.bridge,
                style,
                styleOf: (unit) => styleOfUnit(unit, style),
                slots,
                palette,
                sounds: options.sounds,
                voice: options.voice,
                figures: options.figures,
                pictures: options.pictures,
                onReady: () =>
                    resolve({
                        unlockAudio: () => {
                            const sound = game.sound;

                            if (
                                sound instanceof
                                Phaser.Sound.WebAudioSoundManager
                            ) {
                                void sound.context.resume();
                            } else if (
                                sound instanceof
                                Phaser.Sound.HTML5AudioSoundManager
                            ) {
                                sound.unlock();
                            }
                        },
                        destroy: () => game.destroy(true),
                    }),
            }),
        });
    });
}
