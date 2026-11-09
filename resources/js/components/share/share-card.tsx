import type { CSSProperties, Ref } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import type { ShareCard as ShareCardData } from '@/types';
import { CardDecoration } from './card-decoration';
import { placeColors } from './place-colors';

/*
 * The share card (architecture §6): the 1080×1920 node that becomes the PNG
 * for WhatsApp status. It receives only `card` — the server's list of what
 * may be on it — and draws nothing else: no class, teacher, link or time.
 * One fixed look in light colours, whatever the page's theme; text in
 * Andika (`font-board`), the face made for beginning readers.
 *
 * Everything that matters sits inside WhatsApp's safe area: status covers
 * the top 250 px and the bottom 300 px with its own bars, so those bands
 * and the side margins hold only the decoration.
 */

export const CARD_WIDTH = 1080;
export const CARD_HEIGHT = 1920;
const SAFE_TOP = 250;
const SAFE_BOTTOM = 300;
const SIDE = 72;

const BACKGROUND = '#FFF8E1';
const INK = '#3F3F3F';
const BLUE = '#1E50A0';

/**
 * How densely the students are set, from one big row per child to six small
 * columns. `fitShareCard()` takes the first that fits, so a single winner
 * is large and a whole tied class still fits; names are never cut.
 */
const DENSITIES = [
    { cols: 1, dir: 'row', avatar: 150, name: 64, badge: 56, gap: 24, pad: 32 },
    { cols: 1, dir: 'row', avatar: 116, name: 54, badge: 50, gap: 18, pad: 26 },
    { cols: 2, dir: 'row', avatar: 104, name: 44, badge: 48, gap: 18, pad: 26 },
    { cols: 2, dir: 'row', avatar: 84, name: 36, badge: 44, gap: 16, pad: 24 },
    {
        cols: 3,
        dir: 'column',
        avatar: 96,
        name: 34,
        badge: 44,
        gap: 16,
        pad: 24,
    },
    {
        cols: 3,
        dir: 'column',
        avatar: 80,
        name: 30,
        badge: 40,
        gap: 14,
        pad: 22,
    },
    {
        cols: 4,
        dir: 'column',
        avatar: 76,
        name: 28,
        badge: 38,
        gap: 12,
        pad: 20,
    },
    {
        cols: 5,
        dir: 'column',
        avatar: 68,
        name: 25,
        badge: 36,
        gap: 12,
        pad: 20,
    },
    {
        cols: 6,
        dir: 'column',
        avatar: 60,
        name: 22,
        badge: 34,
        gap: 10,
        pad: 18,
    },
] as const;

type Density = (typeof DENSITIES)[number];

function applyDensity(node: HTMLElement, density: Density): void {
    node.style.setProperty('--cols', String(density.cols));
    node.style.setProperty('--dir', density.dir);
    node.style.setProperty(
        '--align',
        density.dir === 'row' ? 'start' : 'center',
    );
    node.style.setProperty('--avatar', `${density.avatar}px`);
    node.style.setProperty('--name', `${density.name}px`);
    node.style.setProperty('--badge', `${density.badge}px`);
    node.style.setProperty('--gap', `${density.gap}px`);
    node.style.setProperty('--pad', `${density.pad}px`);
}

/**
 * Sets the places as large as they fit between the date and the wordmark,
 * measured with the fonts and pictures the card really uses (call it after
 * they have loaded): the first density that fits as it is. When none does —
 * a very large tie, or long names that wrap — the density whose names stay
 * largest once scaled down to fit, so nothing is ever cut off.
 */
export function fitShareCard(node: HTMLElement): void {
    const box = node.querySelector<HTMLElement>('[data-share-places]');
    const content = node.querySelector<HTMLElement>('[data-share-fit]');

    if (box === null || content === null) {
        return;
    }

    content.style.transform = '';
    let best: { density: Density; scale: number } | null = null;

    for (const density of DENSITIES) {
        applyDensity(node, density);
        const scale = Math.min(1, box.clientHeight / content.offsetHeight);

        if (scale === 1) {
            return;
        }

        if (
            best === null ||
            density.name * scale > best.density.name * best.scale
        ) {
            best = { density, scale };
        }
    }

    if (best !== null) {
        applyDensity(node, best.density);
        content.style.transform = `scale(${Math.min(1, box.clientHeight / content.offsetHeight)})`;
    }
}

type Props = {
    card: ShareCardData;
    ref?: Ref<HTMLDivElement>;
};

export function ShareCard({ card, ref }: Props) {
    return (
        <div
            ref={ref}
            className="relative overflow-hidden font-board"
            style={{
                width: CARD_WIDTH,
                height: CARD_HEIGHT,
                backgroundColor: BACKGROUND,
                color: INK,
            }}
        >
            <CardDecoration />

            <div
                className="absolute flex flex-col items-center"
                style={{
                    top: SAFE_TOP,
                    bottom: SAFE_BOTTOM,
                    left: SIDE,
                    right: SIDE,
                }}
            >
                <p
                    className="w-full text-center text-[88px] leading-[1.05] font-bold text-balance"
                    style={{ color: BLUE }}
                >
                    {card.title}
                </p>
                <p
                    className="mt-6 rounded-full border-4 bg-white px-10 py-2 text-center text-[44px] leading-tight font-bold"
                    style={{ borderColor: BLUE, color: BLUE }}
                >
                    {card.label}
                </p>

                <div
                    data-share-places
                    className="mt-10 flex min-h-0 w-full flex-1 flex-col overflow-hidden"
                >
                    {/* Centred while it fits; auto margins never go negative,
                        so a scaled-down tie stays anchored at the top. */}
                    <div
                        data-share-fit
                        className="my-auto flex shrink-0 origin-top flex-col gap-7"
                    >
                        {card.places.map((place) => (
                            <CardPlace key={place.place} place={place} />
                        ))}
                    </div>
                </div>

                <div className="mt-8 flex items-center gap-5">
                    <span
                        className="flex size-[88px] shrink-0 items-center justify-center rounded-[24px]"
                        style={{ backgroundColor: BLUE }}
                    >
                        <AppLogoIcon className="size-16 fill-white" />
                    </span>
                    <span className="flex flex-col">
                        <span
                            className="text-[56px] leading-none font-bold"
                            style={{ color: BLUE }}
                        >
                            {card.wordmark}
                        </span>
                        {card.domain !== '' && (
                            <span className="mt-2 text-[34px] leading-none">
                                {card.domain}
                            </span>
                        )}
                    </span>
                </div>
            </div>
        </div>
    );
}

function CardPlace({ place }: { place: ShareCardData['places'][number] }) {
    const colors = placeColors(place.place);

    return (
        <section
            className="rounded-[44px] border-[6px] bg-white"
            style={{ borderColor: colors.ring, padding: 'var(--pad, 32px)' }}
        >
            <div className="flex items-center justify-between gap-6">
                <span
                    className="rounded-full border-4 border-black px-[0.8em] py-[0.1em] leading-tight font-bold"
                    style={{
                        backgroundColor: colors.fill,
                        fontSize: 'var(--badge, 56px)',
                    }}
                >
                    {place.place_label}
                </span>
                <span
                    className="text-right leading-tight font-bold"
                    style={{ fontSize: 'var(--badge, 56px)' }}
                >
                    {place.points_label}
                </span>
            </div>

            <ul
                className="flex flex-wrap"
                style={{
                    gap: 'var(--gap, 24px)',
                    marginTop: 'var(--gap, 24px)',
                }}
            >
                {place.students.map((student, index) => (
                    <li key={index} className="flex min-w-0" style={CHIP}>
                        <CardAvatar
                            url={student.avatar_url}
                            name={student.display_name}
                        />
                        <span
                            className="min-w-0 leading-[1.15] font-bold [overflow-wrap:anywhere]"
                            style={{ fontSize: 'var(--name, 64px)' }}
                        >
                            {student.display_name}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** One child: avatar and name, in a row or a column by density. */
const CHIP = {
    width: 'calc((100% - (var(--cols, 1) - 1) * var(--gap, 24px)) / var(--cols, 1))',
    // Set by fitShareCard(); CSS takes a variable where the types want a keyword.
    flexDirection: 'var(--dir, row)' as CSSProperties['flexDirection'],
    alignItems: 'center',
    textAlign: 'var(--align, start)' as CSSProperties['textAlign'],
    gap: 'calc(var(--gap, 24px) * 0.75)',
} satisfies CSSProperties;

const AVATAR = {
    width: 'var(--avatar, 150px)',
    height: 'var(--avatar, 150px)',
} satisfies CSSProperties;

function CardAvatar({ url, name }: { url: string | null; name: string }) {
    if (url === null) {
        // The avatar is gone from content/avatars.json: a plain circle.
        return (
            <span
                className="flex shrink-0 items-center justify-center rounded-full border-4 border-black bg-[#D0CFCE] leading-none font-bold"
                style={{
                    ...AVATAR,
                    fontSize: 'calc(var(--avatar, 150px) * 0.45)',
                }}
            >
                {name.charAt(0)}
            </span>
        );
    }

    return (
        <img
            src={url}
            alt=""
            draggable={false}
            className="shrink-0 object-contain"
            style={AVATAR}
        />
    );
}
