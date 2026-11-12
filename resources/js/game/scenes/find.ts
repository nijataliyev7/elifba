/**
 * The `find` scene, which draws `pop` too (docs/architecture.md §2): a
 * round's items wait on the board in the round's item style; a touch on one
 * goes out through the bridge, and the judge's verdict comes back in the
 * same call stack: the right item plays the round's effect (burst or
 * sparkle) and is gone, a wrong one wobbles and stays. When a round is
 * solved the rest leave and the next round comes in. The pack's `leave`
 * and `arrive` sounds go with that, once a round each (§3 "Sounds"). The
 * voice says the prompt once the items are in, praise after the `solved`
 * sound and the retry phrase after a `miss` (§3 "Voice", `voice.ts`).
 *
 * A round of the picture unit (R5) is drawn in the `picture` style
 * (`options.styleOf`), each tile filled with its item's picture, loaded
 * before the tiles come in. `count.ts` builds on this scene: the same items
 * in a band, under a question of pictures (`ask()`, `answered()`).
 */
import type { HitEffect } from '../bridge';
import type { Round, RoundProgress } from '../contract';
import { figureKey } from '../figures';
import { effect as effectOf } from '../styles/registry';
import type { ItemLayout, ItemStyle, ItemView } from '../styles/types';
import type { SceneOptions } from './board-scene';
import { BoardScene } from './board-scene';

/** From an item starting to come in to the voice saying the prompt. */
const PROMPT_AFTER_MS = 600;

export class FindScene extends BoardScene {
    protected round: Round | null = null;
    private items = new Map<string, ItemView>();
    private stagger: number;

    constructor(options: SceneOptions, key = 'find') {
        super(key, options);
        this.stagger = options.style.stagger;
    }

    // The bridge, in.

    showRound(round: Round, progress: RoundProgress): void {
        const left = [...this.items.values()];

        for (const [index, view] of left.entries()) {
            view.leave(index * this.stagger * 0.55);
        }

        // Items of the round before still on the board (it was skipped).
        if (left.length > 0) {
            this.playIn(0, 'leave');
        }

        this.items.clear();
        this.round = round;
        this.ask(round, progress);

        // The pictures of a picture round are there before its tiles.
        this.loadPictures(
            round.items.map((item) => item.image),
            () => {
                if (this.round?.id === round.id && this.sys.isActive()) {
                    this.place(round, progress);
                }
            },
        );
    }

    applyHit(effect: HitEffect): void {
        if (this.round === null || effect.roundId !== this.round.id) {
            return;
        }

        const view = this.items.get(effect.itemId);

        if (effect.status === 'miss') {
            view?.wobble();
            this.play('miss');
            this.sayRetry(this.round.id);

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
            this.sayPraiseAfter(260, effect.roundId);

            for (const [index, rest] of [...this.items.values()].entries()) {
                rest.leave(300 + index * this.stagger * 0.7);
            }

            if (this.items.size > 0) {
                this.playIn(300, 'leave');
            }

            this.items.clear();
            this.answered(this.round);
        }
    }

    protected scenery(): void {
        this.options.style.scenery?.(this);
    }

    protected currentRound(): Round | null {
        return this.round;
    }

    /** Where the round's items rest: across the free board. */
    protected itemLayout(style: ItemStyle, count: number): ItemLayout {
        return style.layout(count);
    }

    /** Draws what the round asks besides its items; `find` asks nothing on the canvas. */
    protected ask(_round: Round, _progress: RoundProgress): void {}

    /** The round is solved and its items are gone. */
    protected answered(_round: Round): void {}

    private place(round: Round, progress: RoundProgress): void {
        // In an `any` round an item hit correctly is gone and stays gone.
        const shown = round.items.filter(
            (item) => !progress.hit_item_ids.includes(item.id),
        );
        const style = this.options.styleOf(round.unit);
        const { width, height, spots } = this.itemLayout(style, shown.length);
        const palette = this.palette;
        // Chosen over every item, so a reload mid-round shows the same.
        const figures = this.figuresOf(round);

        this.stagger = style.stagger;

        shown.forEach((item, index) => {
            const view = style.create(this, item, {
                ...spots[index],
                width,
                height,
                colour: palette[(index + round.index) % palette.length],
                // A picture item is its own picture; others may stand in front of a figure.
                figure:
                    item.image !== null
                        ? figureKey(item.image)
                        : (figures.get(item.id) ?? null),
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

        // One sound for the round's items coming in, however many there are.
        if (shown.length > 0 && this.isOpen(progress)) {
            this.playIn(250, 'arrive');
        }

        // The prompt once the last item has come in most of the way.
        this.sayPromptIn(
            250 +
                Math.max(0, shown.length - 1) * style.stagger +
                PROMPT_AFTER_MS,
            round,
            progress,
        );
    }
}
