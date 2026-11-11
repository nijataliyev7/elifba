/**
 * The board's item styles, slot styles and effects by key
 * (docs/architecture.md §2, "Item styles and effects"): a pack names its
 * item style (`pack.style.item`), its slot style (`pack.style.slot`, for
 * build rounds) and each round its effect (`round.effect`); the scenes ask
 * here and nowhere else. `Play\Support\Playable::itemStyles()`,
 * `slotStyles()` and `effects()` list the same keys on the server, and
 * `registry.test.ts` checks every key the server lists has its code here.
 */
import type { Effect as EffectKey, Unit } from '../contract';
import { animal } from './animal';
import { balloon } from './balloon';
import { bubble } from './bubble';
import { burst } from './burst';
import { card } from './card';
import { fruit } from './fruit';
import { picture } from './picture';
import { frame } from './slot-frame';
import { wagonSlots } from './slot-wagon';
import { sparkle } from './sparkle';
import type { Effect, ItemStyle, SlotStyle } from './types';
import { wagon } from './wagon';

export const ITEM_STYLES = {
    balloon,
    wagon,
    card,
    bubble,
    animal,
    fruit,
    picture,
} as const satisfies Record<string, ItemStyle>;

export type ItemStyleKey = keyof typeof ITEM_STYLES;

export const SLOT_STYLES = {
    wagon: wagonSlots,
    frame,
} as const satisfies Record<string, SlotStyle>;

export type SlotStyleKey = keyof typeof SLOT_STYLES;

export const EFFECTS: Record<EffectKey, Effect> = {
    burst,
    sparkle,
};

export function isItemStyle(key: string): key is ItemStyleKey {
    return Object.hasOwn(ITEM_STYLES, key);
}

/** The item style a pack names, or null when the board cannot draw it. */
export function itemStyle(key: string): ItemStyle | null {
    return isItemStyle(key) ? ITEM_STYLES[key] : null;
}

/** The slot style a pack names, or null when the board cannot draw it. */
export function slotStyle(key: string): SlotStyle | null {
    return Object.hasOwn(SLOT_STYLES, key)
        ? SLOT_STYLES[key as SlotStyleKey]
        : null;
}

/**
 * The item style a round of `unit` is drawn in (`stage.ts`): every round of
 * the picture unit in the `picture` style, whatever the pack names — a
 * picture on a balloon or behind a wagon would be hidden (ADR 012) — and
 * every other round in the pack's own style (numbers too, as letters are).
 */
export function styleOfUnit(unit: Unit, pack: ItemStyle): ItemStyle {
    return unit === 'picture' ? ITEM_STYLES.picture : pack;
}

export function effect(key: EffectKey): Effect {
    return EFFECTS[key];
}
