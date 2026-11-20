import { Trophy } from 'lucide-react';
import { gameSummary } from '@/components/rooms/catalog';
import { FinalStanding } from '@/components/rooms/final-standing';
import { carriesRoom } from '@/components/rooms/sound-cues';
import type { RoomMotion } from '@/components/rooms/use-motion';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomGame, RoomState } from '@/rooms/contract';

/**
 * A race's end (docs/frontend.md rule 15 "The final", ADR 025): «Oyun
 * bitdi» with what was played, and its final — the room's standing. On a
 * phone the viewer's own row says «siz» and, in a room without a screen,
 * its confetti is the room's; on the screen (`big`) under «Otağın
 * sıralaması», larger and at the screen's pace.
 */
export function RaceEnd({
    state,
    game,
    placeLabels,
    motion,
    big = false,
}: {
    state: RoomState;
    game: RoomGame;
    placeLabels: string[];
    motion: RoomMotion;
    big?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <section
            key={game.id}
            data-motion={motion.phase ? 'on' : undefined}
            className={cn(
                'flex min-w-0 flex-col',
                big ? 'gap-[2.4vh]' : 'gap-3',
            )}
        >
            <div
                className={cn(
                    'flex items-center room-motion:animate-room-enter',
                    big ? 'gap-[2vh]' : 'gap-3',
                )}
            >
                <span
                    className={cn(
                        'flex shrink-0 items-center justify-center rounded-full bg-amber-400 text-amber-950 room-motion:animate-room-pop',
                        big ? 'size-[8vh]' : 'size-11',
                    )}
                >
                    <Trophy
                        aria-hidden
                        className={big ? 'size-[4.4vh]' : 'size-5'}
                    />
                </span>
                <span className="flex min-w-0 flex-col">
                    <span
                        className={cn(
                            big
                                ? 'text-[4.4vh] leading-tight font-bold'
                                : 'font-semibold',
                        )}
                    >
                        {t('rooms.game.over')}
                    </span>
                    <span
                        className={cn(
                            'truncate text-muted-foreground',
                            big ? 'text-[2.4vh]' : 'text-sm',
                        )}
                    >
                        {gameSummary(t, game)}
                    </span>
                </span>
            </div>
            {big && (
                <h2 className="text-[2.8vh] font-semibold">
                    {t('rooms.game.standing')}
                </h2>
            )}
            <FinalStanding
                ranking={state.ranking}
                players={state.players}
                me={big ? null : state.me.player_id}
                placeLabels={placeLabels}
                animate={motion.phase}
                forRoom={!big && carriesRoom(state)}
                big={big}
            />
        </section>
    );
}
