import { Link } from '@inertiajs/react';
import { ChevronRight, Play, Star } from 'lucide-react';
import { NameVoiceMark } from '@/components/students/name-voice-mark';
import { StudentAvatar } from '@/components/students/student-avatar';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { show } from '@/routes/children';
import { create as createGame } from '@/routes/children/games';
import type { Avatar, ChildRow } from '@/types';

type Props = {
    child: ChildRow;
    avatars: Avatar[];
};

/**
 * A child on «Uşaqlarım» (architecture §5 "A child's stars"): the avatar,
 * the name and today's stars only, a way to the child's page and «Oyna»,
 * one tap from the game setup. Big enough for a parent holding a phone
 * with a small child on their lap. A name the board says aloud carries a
 * small speaker (ADR 016 decision 10).
 */
export function ChildCard({ child, avatars }: Props) {
    const { t } = useTranslation();

    return (
        <div className="flex h-full flex-col rounded-2xl border bg-card">
            <Link
                href={show(child.id)}
                prefetch
                className="flex min-w-0 flex-1 items-center gap-4 rounded-t-2xl p-4 transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                data-test="child-card"
            >
                <StudentAvatar
                    avatar={child.avatar}
                    avatars={avatars}
                    initial={child.display_name.charAt(0)}
                    className="size-16 text-2xl"
                />
                <span className="min-w-0 flex-1">
                    <span className="flex min-w-0 items-center gap-1.5">
                        <span className="min-w-0 truncate text-xl font-semibold">
                            {child.display_name}
                        </span>
                        {child.has_voice === true && <NameVoiceMark />}
                    </span>
                    <span className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Star
                            aria-hidden
                            className="size-4 fill-amber-400 text-amber-500"
                        />
                        {t('children.stars.today', {
                            count: child.stars_today,
                        })}
                    </span>
                </span>
                <ChevronRight
                    aria-hidden
                    className="size-5 shrink-0 text-muted-foreground"
                />
            </Link>
            <div className="px-4 pb-4">
                <Button asChild size="lg" className="h-12 w-full text-base">
                    <Link
                        href={createGame(child.id)}
                        aria-label={t('children.play_for', {
                            name: child.display_name,
                        })}
                        data-test="play"
                    >
                        <Play />
                        {t('children.play')}
                    </Link>
                </Button>
            </div>
        </div>
    );
}
