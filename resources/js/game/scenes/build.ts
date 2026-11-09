/**
 * What the three build scenes share (docs/architecture.md §2): a round's
 * tiles wait in the lower band in the pack's item style and its slots
 * stand above them in the pack's slot style. A tile is reusable: a correct
 * hit flies a still copy of it (`style.draw()`) into the next slot and the
 * tile stays, so `nə` can fill both slots of `nənə`; a miss wobbles the
 * tile. Once the last copy lands the round's effect takes the copies
 * (burst, or sparkle for a state symbol), the slots show the unit whole,
 * and everything leaves when the next round comes in. A round shown with
 * progress (a reload mid-round) has its filled slots drawn again.
 *
 * The thin scenes on top (`build-syllable.ts`, `build-word.ts`,
 * `build-sentence.ts`) choose where the slots and tiles stand and what is
 * asked above them (build_word's picture).
 */
import Phaser from 'phaser';
import type { HitEffect } from '../bridge';
import type { Round, RoundProgress } from '../contract';
import { wholeText } from '../slots';
import { BoardItem } from '../styles/item';
import { effect as effectOf } from '../styles/registry';
import type {
    ItemDrawing,
    ItemView,
    SlotStyle,
    SlotView,
} from '../styles/types';
import type { SceneOptions } from './board-scene';
import { BoardScene } from './board-scene';
import type { BuildLayout } from './layouts';

const FLIGHT_MS = 420;
/** How long a new row waits for the old one to clear the board. */
const ENTER_AFTER_MS = 650;
/** How high a copy arcs on its way to the slot. */
const ARC = 150;

/** A still copy of a tile, in a slot or on its way there; the effect takes it at the end. */
class Copy extends BoardItem {
    constructor(
        scene: Phaser.Scene,
        itemId: string,
        colour: number,
        drawing: ItemDrawing,
        readonly fit: number,
    ) {
        super(scene, itemId, colour, drawing, 0, 0);
        drawing.body.disableInteractive();
        this.root.setScale(fit).setDepth(4);
    }

    place(x: number, y: number, scale: number = this.root.scaleX): void {
        this.root.setPosition(x, y).setScale(scale);
    }

    /** Landed: it sits under copies still flying. */
    land(): void {
        this.root.setDepth(3);
        this.scene.tweens.add({
            targets: this.inner,
            scale: { from: 1.12, to: 1 },
            duration: 220,
            ease: 'Back.easeOut',
        });
    }

    /** Drawn again in its slot (a round resumed): it pops in. */
    enter(delay: number): void {
        this.root.setDepth(3);
        this.inner.setScale(0);
        this.scene.tweens.add({
            targets: this.inner,
            scale: 1,
            delay,
            duration: 320,
            ease: 'Back.easeOut',
        });
    }

    leave(delay: number): void {
        if (!this.finish()) {
            return;
        }

        this.scene.tweens.add({
            targets: this.root,
            alpha: 0,
            delay,
            duration: 300,
            onComplete: () => this.destroy(),
        });
    }
}

type Row = {
    round: Round;
    slots: SlotView;
    tiles: Map<string, ItemView>;
    colours: Map<string, number>;
    /** Copies that landed, by slot; the scene keeps each on its slot. */
    copies: (Copy | undefined)[];
    /** Correct hits so far, landed or still flying. */
    filled: number;
    flying: number;
    solved: boolean;
    /** The row has left the board. */
    gone: boolean;
};

export abstract class BuildScene extends BoardScene {
    protected abstract readonly layout: BuildLayout;
    private readonly slotStyle: SlotStyle;
    private row: Row | null = null;
    /** Every row still on the board, the current one and those leaving. */
    private readonly rows = new Set<Row>();

    constructor(key: string, options: SceneOptions) {
        super(key, options);

        if (options.slots === null) {
            // Playable lets no build game start without a slot style.
            throw new Error(`The ${key} scene needs a slot style.`);
        }

        this.slotStyle = options.slots;
    }

    /** Copies ride on their slots, however the slots move. */
    update(): void {
        for (const row of this.rows) {
            row.copies.forEach((copy, index) => {
                if (copy !== undefined) {
                    const spot = row.slots.spot(index);

                    copy.place(spot.x, spot.y);
                }
            });
        }
    }

    // The bridge, in.

    showRound(round: Round, progress: RoundProgress): void {
        // A new row comes in once the old one has mostly gone.
        const wait = this.row === null ? 0 : ENTER_AFTER_MS;

        this.leaveRow();
        this.ask(round, wait);

        const { style } = this.options;
        const palette = this.palette;
        const tiles = style.layout(round.items.length, this.layout.tiles);
        const sample = style.layout(1);
        const slots = this.slotStyle.create(this, {
            count: round.slots,
            band: this.layout.slots,
            aspect: sample.height / sample.width,
            tail: round.prompt.tail,
            palette,
        });
        const row: Row = {
            round,
            slots,
            tiles: new Map(),
            colours: new Map(),
            copies: [],
            filled: 0,
            flying: 0,
            solved: false,
            gone: false,
        };

        this.row = row;
        this.rows.add(row);
        slots.enter(100 + wait);

        round.items.forEach((item, index) => {
            const colour = palette[(index + round.index) % palette.length];
            const view = style.create(this, item, {
                ...tiles.spots[index],
                width: tiles.width,
                colour,
            });

            view.onTouch(() => {
                this.options.bridge.itemTouched({
                    roundId: round.id,
                    itemId: item.id,
                });
            });
            view.enter(350 + index * style.stagger);
            row.tiles.set(item.id, view);
            row.colours.set(item.id, colour);
        });

        // A round resumed mid-way: its filled slots come back.
        const filled = progress.hit_item_ids.slice(0, round.slots);

        filled.forEach((itemId, index) => {
            const copy = this.copyOf(row, index, itemId);

            if (copy !== null) {
                const spot = slots.spot(index);

                copy.place(spot.x, spot.y);
                copy.enter(900 + wait + index * 120);
                row.copies[index] = copy;
            }
        });
        row.filled = filled.length;
        slots.progress(row.filled);
    }

    applyHit(effect: HitEffect): void {
        const row = this.row;

        if (row === null || effect.roundId !== row.round.id || row.solved) {
            return;
        }

        const tile = row.tiles.get(effect.itemId);

        if (effect.status === 'miss') {
            tile?.wobble();
            this.play('miss');

            return;
        }

        if (effect.status !== 'correct' || row.filled >= row.round.slots) {
            return;
        }

        const index = row.filled;

        row.filled += 1;
        row.slots.progress(row.filled);
        this.fly(row, index, effect.itemId);
        this.play('hit');

        if (effect.solved) {
            row.solved = true;

            // The tiles are done; the copy of the last one is still flying.
            [...row.tiles.values()].forEach((rest, at) =>
                rest.leave(350 + at * this.options.style.stagger * 0.7),
            );
            row.tiles.clear();
        }
    }

    /**
     * What the round asks above the slots, shown `wait` ms from now;
     * nothing unless a scene says otherwise.
     */
    protected ask(round: Round, wait: number): void {
        void round;
        void wait;
    }

    protected scenery(): void {
        this.options.style.scenery?.(this, this.layout.tiles);
        this.slotStyle.scenery?.(this, this.layout.slots);
    }

    /** A still copy of a tile, sized for slot `index` and coloured like the tile. */
    private copyOf(row: Row, index: number, itemId: string): Copy | null {
        const item = row.round.items.find(
            (candidate) => candidate.id === itemId,
        );

        if (item === undefined) {
            return null;
        }

        const spot = row.slots.spot(index);
        const colour = row.colours.get(itemId) ?? this.palette[0];
        const drawing = this.options.style.draw(this, item.text, {
            width: spot.width,
            colour,
        });
        const fit = Math.min(
            1,
            spot.width / drawing.width,
            spot.height / drawing.height,
        );

        return new Copy(this, itemId, colour, drawing, fit);
    }

    /** A copy leaves the tile, arcs over the board and lands in slot `index`. */
    private fly(row: Row, index: number, itemId: string): void {
        const copy = this.copyOf(row, index, itemId);

        if (copy === null) {
            return;
        }

        const tile = row.tiles.get(itemId);
        const start = tile?.centre() ?? row.slots.spot(index);
        const startScale =
            tile === undefined
                ? copy.fit
                : (tile.size().width / row.slots.spot(index).width) * copy.fit;

        copy.place(start.x, start.y, startScale);
        row.flying += 1;

        this.tweens.addCounter({
            from: 0,
            to: 1,
            duration: FLIGHT_MS,
            ease: 'Sine.easeInOut',
            onUpdate: (tween) => {
                const t = tween.getValue() ?? 1;
                const to = row.slots.spot(index);

                copy.place(
                    Phaser.Math.Linear(start.x, to.x, t),
                    Phaser.Math.Linear(start.y, to.y, t) -
                        Math.sin(Math.PI * t) * ARC,
                    Phaser.Math.Linear(startScale, copy.fit, t),
                );
            },
            onComplete: () => {
                row.flying -= 1;

                if (row.gone) {
                    copy.destroy();

                    return;
                }

                const to = row.slots.spot(index);

                copy.place(to.x, to.y, copy.fit);
                copy.land();
                row.copies[index] = copy;

                if (row.solved && row.flying === 0) {
                    this.celebrate(row);
                }
            },
        });
    }

    /** The last copy is in: the effect takes the copies and the unit shows whole. */
    private celebrate(row: Row): void {
        const copies = row.copies.filter(
            (copy): copy is Copy => copy !== undefined,
        );
        const effect = effectOf(row.round.effect);

        row.copies = [];
        this.time.delayedCall(120, () => this.play('solved'));
        copies.forEach((copy, at) => {
            this.time.delayedCall(140 + at * 110, () =>
                effect.play(this, copy),
            );
        });
        this.time.delayedCall(260 + copies.length * 110, () =>
            row.slots.complete(wholeText(row.round)),
        );
    }

    /** The round on the board leaves: tiles, slots and the copies riding on them. */
    private leaveRow(): void {
        const row = this.row;

        if (row === null) {
            return;
        }

        this.row = null;

        [...row.tiles.values()].forEach((tile, at) =>
            tile.leave(at * this.options.style.stagger * 0.55),
        );
        row.tiles.clear();
        row.slots.leave(0, () => {
            row.gone = true;

            for (const copy of row.copies) {
                copy?.destroy();
            }

            row.copies = [];
            this.rows.delete(row);
        });
    }
}
