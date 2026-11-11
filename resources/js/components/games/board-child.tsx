import { studentAvatars } from '@/components/games/student-grid';
import { StudentAvatar } from '@/components/students/student-avatar';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { BoardChild as BoardChildProps } from '@/types';

type Props = {
    child: BoardChildProps;
    /** The pack's accent on the board; none on the phone, which uses the tokens. */
    accent?: string;
    className?: string;
};

/**
 * Who is at a home board (architecture §4 "At home"): the game's child,
 * always, in place of the class's grid and «Növbəti». Nothing to choose,
 * so it is not a button.
 */
export function BoardChild({ child, accent, className }: Props) {
    const { t } = useTranslation();

    return (
        <p
            className={cn(
                'flex min-w-0 items-center gap-3 rounded-2xl border-2 px-3 py-2 max-lg:gap-2 max-lg:px-2 max-lg:py-1',
                accent === undefined ? 'bg-card' : 'bg-white shadow-xs',
                className,
            )}
            style={accent === undefined ? undefined : { borderColor: accent }}
            data-test="board-child"
        >
            <StudentAvatar
                avatar={child.avatar.key}
                avatars={studentAvatars([child])}
                initial={child.display_name.slice(0, 1)}
                className="size-14 text-xl max-lg:size-10 max-lg:text-base"
            />
            <span className="min-w-0">
                <span
                    className={cn(
                        'block text-sm font-medium max-lg:text-xs',
                        accent === undefined
                            ? 'text-muted-foreground'
                            : 'text-neutral-500',
                    )}
                >
                    {t('games.board.playing')}
                </span>
                <span className="block max-w-[28ch] truncate font-board text-2xl font-bold max-lg:max-w-[14ch] max-lg:text-lg">
                    {child.display_name}
                </span>
            </span>
        </p>
    );
}
