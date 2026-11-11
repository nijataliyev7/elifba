import { Lock } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The parts the game setup is made of (docs/frontend.md rule 13): a
 * numbered question, a tile (units, mechanics, themes) and a row button
 * (levels, rounds, letter case, letters, words). Every choice is a
 * `button type="button"` with `aria-pressed`; a locked one is dashed and
 * muted with a lock, `aria-disabled` but focusable, and never chosen.
 */

/** A numbered question: a `fieldset` whose legend carries its number in a filled circle. */
export function SetupQuestion({
    number,
    title,
    children,
}: {
    number: number;
    title: string;
    children: ReactNode;
}) {
    return (
        <fieldset className="grid min-w-0 gap-3">
            <legend className="mb-3 flex items-center gap-2 text-base font-semibold">
                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground tabular-nums">
                    {number}
                </span>
                {title}
            </legend>
            {children}
        </fieldset>
    );
}

/** A sub-question's label inside a question or «Əlavə». */
export function SetupLabel({ children }: { children: ReactNode }) {
    return (
        <legend className="mb-2 text-sm font-medium text-muted-foreground">
            {children}
        </legend>
    );
}

type TileProps = {
    chosen: boolean;
    locked?: boolean;
    /** A lucide icon in the 36px square, or a picture (a theme's cover). */
    icon?: ComponentType<{ className?: string }>;
    picture?: string;
    title: string;
    /** The muted line under the title; a locked tile shows its reason here. */
    line?: ReactNode;
    /** Not a button: the only choice there is (a level with one mechanic). */
    only?: boolean;
    /** A theme: the cover above the title. */
    stacked?: boolean;
    onClick?: () => void;
};

/** A tile: a unit, a mechanic or a theme. */
export function SetupTile({
    chosen,
    locked = false,
    icon: Icon,
    picture,
    title,
    line,
    only = false,
    stacked = false,
    onClick,
}: TileProps) {
    const className = cn(
        'flex min-h-11 w-full min-w-0 gap-3 rounded-xl border-2 p-3 text-left transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
        stacked ? 'flex-col items-center text-center' : 'items-center',
        chosen
            ? 'border-primary bg-accent'
            : locked
              ? 'border-dashed bg-transparent text-muted-foreground'
              : 'border-border bg-card hover:bg-accent/60',
    );
    const content = (
        <>
            {Icon !== undefined && (
                <span
                    aria-hidden
                    className={cn(
                        'flex size-9 shrink-0 items-center justify-center rounded-lg',
                        chosen
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground',
                    )}
                >
                    <Icon className="size-5" />
                </span>
            )}
            {picture !== undefined && (
                <img
                    src={picture}
                    alt=""
                    width={48}
                    height={48}
                    className={cn(
                        'size-12 shrink-0 object-contain',
                        locked && 'opacity-50 grayscale',
                    )}
                />
            )}
            <span
                className={cn(
                    'flex min-w-0 flex-1 flex-col gap-0.5',
                    stacked && 'items-center',
                )}
            >
                <span className="flex max-w-full items-center gap-1.5 font-medium">
                    <span className="truncate">{title}</span>
                    {locked && (
                        <Lock aria-hidden className="size-3.5 shrink-0" />
                    )}
                </span>
                {line !== undefined && line !== null && (
                    <span className="text-xs text-muted-foreground">
                        {line}
                    </span>
                )}
            </span>
        </>
    );

    if (only) {
        return <div className={className}>{content}</div>;
    }

    return (
        <button
            type="button"
            aria-pressed={chosen}
            aria-disabled={locked || undefined}
            onClick={onClick}
            className={cn(className, locked && 'cursor-default')}
        >
            {content}
        </button>
    );
}

type RowButtonProps = {
    chosen: boolean;
    locked?: boolean;
    children: ReactNode;
    className?: string;
    onClick?: () => void;
    lang?: string;
};

/**
 * A row button: chosen is the `default` look, the others `outline`; at
 * least 44px. A locked one is dashed with a lock.
 */
export function SetupRowButton({
    chosen,
    locked = false,
    children,
    className,
    onClick,
    lang,
}: RowButtonProps) {
    return (
        <button
            type="button"
            lang={lang}
            aria-pressed={chosen}
            aria-disabled={locked || undefined}
            onClick={onClick}
            className={cn(
                'inline-flex min-h-11 min-w-11 items-center justify-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                chosen
                    ? 'border-primary bg-primary text-primary-foreground'
                    : locked
                      ? 'cursor-default border-dashed bg-transparent text-muted-foreground'
                      : 'bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:border-input dark:bg-input/30 dark:hover:bg-input/50',
                className,
            )}
        >
            {children}
            {locked && <Lock aria-hidden className="size-3.5 shrink-0" />}
        </button>
    );
}
