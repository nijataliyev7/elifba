import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/** One line of a game's end beside its badge: the result in words, or a muted line. */
export type EndLine = {
    text: string;
    /** The result: «Oyun bitdi», «:name qalib gəldi». */
    strong?: boolean;
    /** A muted line that may wrap, as a match's ending does; otherwise it is cut to one line. */
    wrap?: boolean;
};

/**
 * The head of a game's end (docs/frontend.md rule 15 "The final", "Board
 * games" → "The end"): a round badge with the end's icon — on the room's
 * amber, or muted for a match that was stopped — beside its lines, entering
 * as the final does. A race's and a match's end alike; `big` is the
 * screen's size.
 */
export function EndHead({
    icon: Icon,
    muted = false,
    lines,
    big = false,
}: {
    icon: LucideIcon;
    muted?: boolean;
    lines: EndLine[];
    big?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-center room-motion:animate-room-enter',
                big ? 'gap-[2vh]' : 'gap-3',
            )}
        >
            <span
                className={cn(
                    'flex shrink-0 items-center justify-center rounded-full room-motion:animate-room-pop',
                    big ? 'size-[8vh]' : 'size-11',
                    muted
                        ? 'bg-muted text-muted-foreground'
                        : 'bg-amber-400 text-amber-950',
                )}
            >
                <Icon aria-hidden className={big ? 'size-[4.4vh]' : 'size-5'} />
            </span>
            <span className="flex min-w-0 flex-col">
                {lines.map((line) => (
                    <span
                        key={line.text}
                        className={cn(
                            line.strong
                                ? big
                                    ? 'text-[4.4vh] leading-tight font-bold'
                                    : 'font-semibold'
                                : cn(
                                      'text-muted-foreground',
                                      big ? 'text-[2.4vh]' : 'text-sm',
                                      !line.wrap && 'truncate',
                                  ),
                        )}
                    >
                        {line.text}
                    </span>
                ))}
            </span>
        </div>
    );
}
