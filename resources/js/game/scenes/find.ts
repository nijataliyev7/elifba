/**
 * The `find` scene, which draws `pop` too (docs/architecture.md §2): a
 * round's items wait on the board in the pack's item style; a touch on one goes out through the
 * bridge, and the judge's verdict comes back in the same call stack: the
 * right item plays the round's effect (burst or sparkle) and is gone, a
 * wrong one wobbles and stays. When a round is solved the rest leave and
 * the next round comes in.
 */
import type { HitEffect } from '../bridge';
import type { Round, RoundProgress } from '../contract';
import { effect as effectOf } from '../styles/registry';
import type { ItemView } from '../styles/types';
import type { SceneOptions } from './board-scene';
import { BoardScene } from './board-scene';

export class FindScene extends BoardScene {
    private round: Round | null = null;
    private items = new Map<string, ItemView>();

    constructor(options: SceneOptions) {
        super('find', options);
    }

    // The bridge, in.

    showRound(round: Round, progress: RoundProgress): void {
        for (const [index, view] of [...this.items.values()].entries()) {
            view.leave(index * this.options.style.stagger * 0.55);
        }

        this.items.clear();
        this.round = round;

        // In an `any` round an item hit correctly is gone and stays gone.
        const shown = round.items.filter(
            (item) => !progress.hit_item_ids.includes(item.id),
        );
        const { style } = this.options;
        const { width, height, spots } = style.layout(shown.length);
        const palette = this.palette;
        // Chosen over every item, so a reload mid-round shows the same.
        const figures = this.figuresOf(round);

        shown.forEach((item, index) => {
            const view = style.create(this, item, {
                ...spots[index],
                width,
                height,
                colour: palette[(index + round.index) % palette.length],
                figure: figures.get(item.id) ?? null,
            });

            view.onTouch(() => {
                this.options.bridge.itemTouched({
                    roundId: round.id,
                    itemId: item.id,
                });
            });
            view.enter(250 + index * style.stagger);
            this.items.set(item.id, view);
        });
    }

    applyHit(effect: HitEffect): void {
        if (this.round === null || effect.roundId !== this.round.id) {
            return;
        }

        const view = this.items.get(effect.itemId);

        if (effect.status === 'miss') {
            view?.wobble();
            this.play('miss');

            return;
        }

        if (effect.status !== 'correct' || view === undefined) {
            return;
        }

        this.items.delete(effect.itemId);
        effectOf(this.round.effect).play(this, view);

        this.play('hit');

        if (effect.solved) {
            this.time.delayedCall(260, () => this.play('solved'));

            for (const [index, rest] of [...this.items.values()].entries()) {
                rest.leave(300 + index * this.options.style.stagger * 0.7);
            }

            this.items.clear();
        }
    }

    protected scenery(): void {
        this.options.style.scenery?.(this);
    }
}
