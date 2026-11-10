/**
 * What item styles and effects are (docs/architecture.md §2, "Item styles
 * and effects"). A pack names one of each (`pack.style.item`,
 * `round.effect`); `registry.ts` maps the key to the code here, and
 * `Play\Support\Playable::itemStyles()` / `effects()` list what exists.
 *
 * A style can draw an item twice over: as a still picture (`draw()`, which
 * the build scenes fly into a slot) and as a living `ItemView` on the board
 * built on that picture (`create()`).
 *
 * A pack's slot style (`pack.style.slot`, A8.3) draws the slots of a build
 * round: where each tile's copy lands, the fixed tail after the last slot,
 * and the unit shown whole once the round is solved.
 */
import type Phaser from 'phaser';
import type { RoundItem } from '../contract';

export type ItemViewOptions = {
    x: number;
    y: number;
    /** The item's width; the style picks its height. */
    width: number;
    /** A pack palette colour. */
    colour: number;
    /** The item's height from its layout (`ItemLayout.height`), for a style that fills its box. */
    height?: number;
    /** The texture of the figure behind the item (`animal`, `fruit`); other styles ignore it. */
    figure?: string | null;
};

/** How a style draws one item, without motion. */
export type ItemLook = {
    /** The item's width; the style picks its height. */
    width: number;
    /** A pack palette colour. */
    colour: number;
    /**
     * The height of the box the item fills: `ItemLayout.height` for a
     * tile, a slot's height for its copy. `animal` and `fruit` items fill
     * it (taller on the free board, compact in a build band); other styles
     * pick their height from the width.
     */
    height?: number;
    /**
     * The texture of the figure the item stands in front of (§2 "Figures":
     * `animal`, `fruit`), loaded by the scene; null or missing draws the
     * plate alone. Other styles ignore it.
     */
    figure?: string | null;
};

/** A still picture of an item, centred on (0, 0) of `root`. */
export type ItemDrawing = {
    root: Phaser.GameObjects.Container;
    /** The part a touch lands on; it has its hit area, not yet interactive. */
    body: Phaser.GameObjects.GameObject;
    hitArea: Phaser.Types.Input.InputConfiguration;
    width: number;
    height: number;
};

export type ItemView = {
    readonly itemId: string;
    readonly colour: number;
    onTouch: (callback: () => void) => void;
    centre: () => { x: number; y: number };
    size: () => { width: number; height: number };
    /** Appears when its round starts, `delay` ms after the round does. */
    enter: (delay: number) => void;
    /** A miss: the item stays. */
    wobble: () => void;
    /** Hit correctly with `burst`: swells for a moment and is gone. */
    pop: () => void;
    /** Hit correctly with `sparkle`: lifts and fades away whole, never broken. */
    lift: () => void;
    /** Its round is over: it leaves the canvas. */
    leave: (delay: number) => void;
    destroy: () => void;
};

/** A strip of the 1920×1080 board, top to bottom: where a build round's tiles or slots stand. */
export type Band = { top: number; bottom: number };

/** Where `count` items rest on the 1920×1080 board, and how big each is. */
export type ItemLayout = {
    width: number;
    /** The height of one item drawn at `width` (its body, as `draw()` gives it when given this height). */
    height: number;
    spots: { x: number; y: number }[];
};

export type ItemStyle = {
    /**
     * Where `count` items rest: across the board (find), or inside `band`
     * (a build round's tiles under its slots), sized to fit it.
     */
    layout: (count: number, band?: Band) => ItemLayout;
    /** ms between one item's entrance and the next (0: they move as one). */
    stagger: number;
    /** Things drawn once behind every round (the train's rails, under `band` when given); optional. */
    scenery?: (scene: Phaser.Scene, band?: Band) => void;
    draw: (scene: Phaser.Scene, text: string, look: ItemLook) => ItemDrawing;
    create: (
        scene: Phaser.Scene,
        item: RoundItem,
        options: ItemViewOptions,
    ) => ItemView;
};

/** What a correct hit does to its item; the item is gone afterwards. */
export type Effect = {
    play: (scene: Phaser.Scene, view: ItemView) => void;
};

/** One slot on the board: its centre and the box a tile's copy fits in. */
export type SlotSpot = { x: number; y: number; width: number; height: number };

export type SlotRowOptions = {
    /** Slots in the round: the solution's length. */
    count: number;
    /** The strip the slots stand in. */
    band: Band;
    /** height ÷ width of the pack's items, so a slot hugs the copy it takes. */
    aspect: number;
    /** The fixed `.`, `?` or `!` after the last slot (`prompt.tail`), or null. */
    tail: string | null;
    /** The pack palette as numbers; never empty. */
    palette: number[];
};

/**
 * A build round's row of slots. The scene keeps each landed copy on its
 * slot's `spot()` every frame, so the copies move with the row (a train
 * rolling in or away) without belonging to it.
 */
export type SlotView = {
    /** Where slot `index` is now, on the board. */
    spot: (index: number) => SlotSpot;
    /** Comes in, `delay` ms after the round does. */
    enter: (delay: number) => void;
    /** The first `filled` slots hold their copy; the next one waits for its piece. */
    progress: (filled: number) => void;
    /** The round is solved and its copies are gone: shows the unit whole (`TUT`, `nənə`, `Tar gözəldir.`). */
    complete: (text: string) => void;
    /** Leaves the board, `delay` ms from now; `gone` runs once it has. */
    leave: (delay: number, gone: () => void) => void;
    destroy: () => void;
};

export type SlotStyle = {
    /** Things drawn once behind every round (the slot train's rails); optional. */
    scenery?: (scene: Phaser.Scene, band: Band) => void;
    create: (scene: Phaser.Scene, options: SlotRowOptions) => SlotView;
};
