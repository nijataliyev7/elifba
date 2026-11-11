/**
 * What every item style shares (docs/architecture.md §2): the label in
 * Andika, colour shading, and `BoardItem`, the living item built on a
 * style's still drawing. A style subclasses `BoardItem` for how its items
 * enter, idle, wobble and leave; `pop()` (burst) and `lift()` (sparkle) are
 * the same for all.
 *
 * Text is drawn exactly as the server sends it (it is cased there) in
 * Andika, which the stage has loaded before any item exists.
 */
import Phaser from 'phaser';
import { textOn } from '../palette';
import { rgba, tones } from './fluent';
import type { ItemDrawing, ItemView } from './types';

/** What `textOn()` gives for a dark ground. */
const WHITE_TEXT = '#FFFFFF';

/** Characters that reach above and below Latin letters in Azerbaijani text. */
const MEASURE = '|ÉİĞŞÇÖÜƏqgyp';

/** Darker (`amount` < 0) or lighter (> 0) towards black or white. */
export function shade(colour: number, amount: number): number {
    const { r, g, b } = Phaser.Display.Color.IntegerToRGB(colour);
    const mix = (channel: number) =>
        Math.round(
            amount < 0
                ? channel * (1 + amount)
                : channel + (255 - channel) * amount,
        );

    return Phaser.Display.Color.GetColor(mix(r), mix(g), mix(b));
}

export function hex(colour: number): string {
    return `#${colour.toString(16).padStart(6, '0')}`;
}

/**
 * An item's text, bold, readable on `ground`, at most `room` wide: long
 * words shrink to fit, letters stay big. White letters on a lit surface
 * (`styles/fluent.ts`) sit on a soft shade of the ground's own deep tone,
 * never an outline, so they stay clear where the light falls.
 */
export function itemLabel(
    scene: Phaser.Scene,
    text: string,
    size: number,
    ground: number,
    room: number,
): Phaser.GameObjects.Text {
    const color = textOn(hex(ground));
    const label = scene.add.text(0, 0, text, {
        fontFamily: 'Andika',
        fontStyle: 'bold',
        fontSize: `${Math.round(size)}px`,
        color,
        testString: MEASURE,
        padding: { x: 8, y: 12 },
    });

    label.setOrigin(0.5);

    if (color === WHITE_TEXT) {
        label.setShadow(
            0,
            Math.max(2, size * 0.025),
            rgba(tones(ground).deep, 0.45),
            Math.max(4, size * 0.06),
            false,
            true,
        );
    }

    if (label.width > room) {
        label.setScale(room / label.width);
    }

    return label;
}

export abstract class BoardItem implements ItemView {
    readonly itemId: string;
    readonly colour: number;
    protected readonly scene: Phaser.Scene;
    /** Moves (position) without fighting the idle motion of `inner`. */
    protected readonly root: Phaser.GameObjects.Container;
    /** Wobbles, swells and sways (angle, scale). */
    protected readonly inner: Phaser.GameObjects.Container;
    protected readonly drawing: ItemDrawing;
    protected readonly restX: number;
    protected readonly restY: number;
    protected gone = false;
    private idles: Phaser.Tweens.Tween[] = [];

    constructor(
        scene: Phaser.Scene,
        itemId: string,
        colour: number,
        drawing: ItemDrawing,
        x: number,
        y: number,
    ) {
        this.scene = scene;
        this.itemId = itemId;
        this.colour = colour;
        this.drawing = drawing;
        this.restX = x;
        this.restY = y;
        this.inner = drawing.root;
        this.root = scene.add.container(x, y, [this.inner]);
        drawing.body.setInteractive(drawing.hitArea);
    }

    onTouch(callback: () => void): void {
        this.drawing.body.on('pointerdown', callback);
    }

    centre(): { x: number; y: number } {
        return { x: this.root.x, y: this.root.y };
    }

    size(): { width: number; height: number } {
        return { width: this.drawing.width, height: this.drawing.height };
    }

    abstract enter(delay: number): void;

    /** A wrong item shakes and stays. */
    wobble(): void {
        if (this.gone) {
            return;
        }

        this.scene.tweens.killTweensOf(this.inner);
        this.inner.setAngle(0).setScale(1);
        this.scene.tweens.add({
            targets: this.inner,
            angle: [-10, 8, -6, 4, 0],
            duration: 480,
            ease: 'Sine.easeInOut',
        });
    }

    /** The burst takes over: the item swells for a moment and is gone. */
    pop(): void {
        if (!this.finish()) {
            return;
        }

        this.scene.tweens.add({
            targets: this.inner,
            scale: 1.18,
            duration: 70,
            onComplete: () => this.destroy(),
        });
    }

    /** The sparkle takes over: the item rises, glows and fades away whole. */
    lift(): void {
        if (!this.finish()) {
            return;
        }

        this.scene.tweens.add({
            targets: this.inner,
            angle: 0,
            scale: 1.1,
            duration: 260,
            ease: 'Back.easeOut',
        });
        this.scene.tweens.add({
            targets: this.root,
            y: this.root.y - 150,
            alpha: 0,
            delay: 180,
            duration: 820,
            ease: 'Sine.easeIn',
            onComplete: () => this.destroy(),
        });
    }

    abstract leave(delay: number): void;

    destroy(): void {
        this.gone = true;
        this.stopIdling();
        this.scene.tweens.killTweensOf([this.root, this.inner]);
        this.root.destroy();
    }

    /** Ends the item's play: no more touches, no idle motion. */
    protected finish(): boolean {
        if (this.gone) {
            return false;
        }

        this.gone = true;
        this.drawing.body.disableInteractive();
        this.stopIdling();

        return true;
    }

    /** Starts the looping motion of an item that waits to be touched. */
    protected idle(tweens: Phaser.Types.Tweens.TweenBuilderConfig[]): void {
        if (this.gone) {
            return;
        }

        this.idles = tweens.map((config) =>
            this.scene.tweens.add({ yoyo: true, repeat: -1, ...config }),
        );
    }

    private stopIdling(): void {
        for (const tween of this.idles) {
            tween.stop();
        }

        this.idles = [];
    }
}
