import { ListOrdered, X } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { moveRows } from '@/components/rooms/draughts/board';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * «Gedişlər» (docs/frontend.md rule 15 "Board games", ADR 034 decision
 * 28): the state's moves as the server wrote them, numbered as the record
 * numbers them — white's move with black's reply on one line, «12. c3:e5
 * f6-g5» — in tabular figures, the last one marked (`bg-accent`,
 * `aria-current`) and scrolled into view. Nothing in it is a link; nothing
 * is replayed. `big` is the screen's column.
 */
export function MoveLines({
    moves,
    big = false,
    className,
}: {
    moves: string[];
    big?: boolean;
    className?: string;
}) {
    const { t } = useTranslation();
    const rows = moveRows(moves);
    const lastRef = useRef<HTMLSpanElement>(null);
    const lastIndex = moves.length - 1;

    useEffect(() => {
        lastRef.current?.scrollIntoView({ block: 'nearest' });
    }, [lastIndex]);

    if (rows.length === 0) {
        return (
            <p
                className={cn(
                    'text-muted-foreground',
                    big ? 'text-[max(0.875rem,2vh)]' : 'text-sm',
                    className,
                )}
            >
                {t('rooms.match.no_moves')}
            </p>
        );
    }

    const move = (line: string, index: number) => (
        <span
            ref={index === lastIndex ? lastRef : undefined}
            aria-current={index === lastIndex ? 'true' : undefined}
            className={cn(
                'rounded px-1',
                index === lastIndex && 'bg-accent font-semibold',
            )}
        >
            {line}
        </span>
    );

    return (
        <ol
            className={cn(
                'flex flex-col overflow-y-auto tabular-nums',
                big
                    ? 'gap-[0.4vh] text-[max(0.875rem,2.1vh)]'
                    : 'gap-0.5 text-sm',
                className,
            )}
        >
            {rows.map((row, index) => (
                <li
                    key={row.number}
                    className="grid grid-cols-[2.5em_minmax(0,1fr)_minmax(0,1fr)] items-baseline gap-1"
                >
                    <span className="text-right text-muted-foreground">
                        {row.number}.
                    </span>
                    {move(row.white, index * 2)}
                    {row.black !== null ? (
                        move(row.black, index * 2 + 1)
                    ) : (
                        <span />
                    )}
                </li>
            ))}
        </ol>
    );
}

/**
 * «Gedişlər» on a phone: a 44px button with the number of the last move,
 * opening the list as a sheet from the bottom below `sm` and a dialog from
 * there.
 */
export function MoveListButton({
    moves,
    className,
}: {
    moves: string[];
    className?: string;
}) {
    const { t } = useTranslation();
    const number = Math.ceil(moves.length / 2);

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className={cn('h-11', className)}
                >
                    <ListOrdered />
                    {t('rooms.match.moves')}
                    {number > 0 && (
                        <span className="text-muted-foreground tabular-nums">
                            {number}
                        </span>
                    )}
                </Button>
            </DialogTrigger>
            <DialogContent
                showCloseButton={false}
                aria-describedby={undefined}
                className="top-auto bottom-0 left-0 flex max-h-[80dvh] max-w-none translate-x-0 translate-y-0 flex-col gap-0 rounded-none rounded-t-2xl border-x-0 border-b-0 p-0 data-[state=closed]:zoom-out-100 data-[state=open]:zoom-in-100 motion-reduce:animate-none sm:top-[50%] sm:bottom-auto sm:left-[50%] sm:max-w-sm sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-2xl sm:border"
            >
                <div className="flex items-center gap-3 border-b px-4 py-2">
                    <DialogTitle className="min-w-0 flex-1 text-lg">
                        {t('rooms.match.moves')}
                    </DialogTitle>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            className="size-11 shrink-0 rounded-full"
                            aria-label={t('common.close')}
                        >
                            <X className="size-5" />
                        </Button>
                    </DialogClose>
                </div>
                <MoveLines
                    moves={moves}
                    className="min-h-0 flex-1 px-4 pt-3 pb-[max(env(safe-area-inset-bottom),1rem)]"
                />
            </DialogContent>
        </Dialog>
    );
}
