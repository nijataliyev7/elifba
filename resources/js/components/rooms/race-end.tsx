import { Trophy } from 'lucide-react';
import { gameSummary } from '@/components/rooms/catalog';
import { EndHead } from '@/components/rooms/end-head';
import { GameFinal } from '@/components/rooms/final-standing';
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
            <EndHead
                icon={Trophy}
                lines={[
                    { text: t('rooms.game.over'), strong: true },
                    { text: gameSummary(t, game) },
                ]}
                big={big}
            />
            {big && (
                <h2 className="text-[2.8vh] font-semibold">
                    {t('rooms.game.standing')}
                </h2>
            )}
            <GameFinal
                state={state}
                placeLabels={placeLabels}
                animate={motion.phase}
                big={big}
            />
        </section>
    );
}
