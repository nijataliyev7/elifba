import type { ReactNode } from 'react';
import type { SteppedCount } from '@/components/rooms/domino/turn';
import { cn } from '@/lib/utils';

/**
 * A count as a turn's steps change it (`stepCounts()`): on a page that saw
 * the moment arrive (`moving`), each value stands in its window of the
 * turn's timeline — the first from the start, the last from its step on —
 * stacked in one place; otherwise, and under reduced motion, the state's
 * own value alone. Visibility only (`domino-window`): nothing moves.
 */
export function Stepped({
    counts,
    moving,
    render,
}: {
    counts: SteppedCount;
    moving: boolean;
    render: (value: number) => ReactNode;
}) {
    const last = counts[counts.length - 1];

    if (last === undefined) {
        return null;
    }

    if (!moving || counts.length === 1) {
        return <>{render(last.value)}</>;
    }

    return (
        <span className="inline-grid">
            {/* The state's own value holds the place and is what a screen reader reads. */}
            <span className="invisible col-start-1 row-start-1">
                {render(last.value)}
            </span>
            {counts.map((count, index) => {
                const next = counts[index + 1];

                return (
                    <span
                        key={index}
                        aria-hidden
                        className={cn(
                            'domino-window col-start-1 row-start-1 motion-reduce:hidden',
                        )}
                        style={{
                            animationDelay: `${count.from}ms`,
                            animationDuration:
                                next === undefined
                                    ? '1ms'
                                    : `${next.from - count.from}ms`,
                            animationFillMode:
                                next === undefined ? 'forwards' : 'none',
                        }}
                    >
                        {render(count.value)}
                    </span>
                );
            })}
            <span
                aria-hidden
                className="col-start-1 row-start-1 hidden motion-reduce:inline"
            >
                {render(last.value)}
            </span>
        </span>
    );
}

/**
 * What one step shows on a seat's chip — «Bazar 2×», «PAS» — in its window
 * only: from `at` for `length` ms of a moment the page saw arrive, popping
 * in where motion is allowed.
 */
export function StepMark({
    at,
    length,
    step,
    className,
    children,
}: {
    at: number;
    length: number;
    /** What the step is, for a page's reader: `draw`, `pass`. */
    step: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <span
            data-step={step}
            className={cn('domino-window', className)}
            style={{
                animationDelay: `${at}ms`,
                animationDuration: `${length}ms`,
            }}
        >
            <span
                className="inline-flex motion-safe:animate-room-pop"
                style={{ animationDelay: `${at}ms` }}
            >
                {children}
            </span>
        </span>
    );
}
