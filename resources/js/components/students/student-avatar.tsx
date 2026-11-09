import { cn } from '@/lib/utils';
import type { Avatar } from '@/types';

type Props = {
    /** The avatar key (`content/avatars.json`). */
    avatar: string;
    /** Every avatar with its picture's URL, from the page props. */
    avatars: Avatar[];
    /** Shown in a plain circle if the key has no picture any more. */
    initial?: string;
    className?: string;
};

/**
 * A student's avatar: Kenney's CC0 animal picture for the key
 * (docs/architecture.md §8). The URL comes from the server; a key that is no
 * longer in `content/avatars.json` falls back to a plain circle with the
 * name's first letter. Decorative: the name is always shown beside it.
 */
export function StudentAvatar({ avatar, avatars, initial, className }: Props) {
    const url = avatars.find(({ key }) => key === avatar)?.url;

    if (url === undefined) {
        return (
            <span
                aria-hidden
                data-avatar={avatar}
                className={cn(
                    'inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold text-muted-foreground select-none',
                    className,
                )}
            >
                {initial}
            </span>
        );
    }

    return (
        <img
            src={url}
            alt=""
            aria-hidden
            draggable={false}
            data-avatar={avatar}
            className={cn(
                'inline-block size-9 shrink-0 object-contain select-none',
                className,
            )}
        />
    );
}
