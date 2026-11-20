import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A live game's title in the game frame's top bar (ADR 032 decision 4):
 * the page's `h1` — a round's «:number-ci raund», a match's «Şaşki» — and
 * one muted line under it; the title pops in when the page saw its moment
 * begin.
 */
export function FrameTitle({
    title,
    titleKey,
    line,
    motion,
}: {
    title: string;
    /** What the title is of: a new one pops in. */
    titleKey: string | number;
    line: string;
    motion: boolean;
}) {
    return (
        <>
            <h1
                key={titleKey}
                id="round-heading"
                className={cn(
                    'origin-left truncate text-base leading-tight font-semibold tabular-nums',
                    motion && 'motion-safe:animate-room-pop',
                )}
            >
                {title}
            </h1>
            <p className="truncate text-xs text-muted-foreground tabular-nums">
                {line}
            </p>
        </>
    );
}

/**
 * A live game's head on the screen, under the strip (docs/frontend.md rule
 * 15): one muted line — «Söz tap · Orta», «Şaşki · 5+3» — over the title —
 * a round's «:number-ci raund», a match's «Gediş :number» — with `aside`
 * on its right (a round's seconds) and `children` under it (a round's time
 * bar); the title pops in when the page saw its moment begin.
 */
export function ScreenHead({
    line,
    title,
    titleKey,
    motion,
    aside,
    children,
}: {
    line: string;
    title: string;
    /** What the title is of: a new one pops in. */
    titleKey: string | number;
    motion: boolean;
    aside?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-[1.2vh]">
            <p className="truncate text-[2.2vh] text-muted-foreground tabular-nums">
                {line}
            </p>
            <div className="flex items-baseline justify-between gap-3">
                <h2
                    key={titleKey}
                    className={cn(
                        'origin-left text-[3.4vh] font-semibold tabular-nums',
                        motion && 'motion-safe:animate-room-pop',
                    )}
                >
                    {title}
                </h2>
                {aside}
            </div>
            {children}
        </div>
    );
}
