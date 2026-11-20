import { CircleStop, Handshake, Trophy } from 'lucide-react';
import { gameSummary } from '@/components/rooms/catalog';
import { DraughtsBoard } from '@/components/rooms/draughts/draughts-board';
import { MoveListButton } from '@/components/rooms/draughts/move-list';
import { finalRows } from '@/components/rooms/final';
import { FinalStanding } from '@/components/rooms/final-standing';
import { carriesRoom } from '@/components/rooms/sound-cues';
import { StandingCard } from '@/components/rooms/start-parts';
import type { RoomMotion } from '@/components/rooms/use-motion';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomGame, RoomMatch, RoomState } from '@/rooms/contract';

type Over = Extract<RoomMatch, { phase: 'over' }>;

/**
 * A match's end (docs/frontend.md rule 15 "Board games" → "The end", ADR
 * 034 decisions 25 and 26): under «Oyun bitdi» the result in words — «:name
 * qalib gəldi» or «Heç-heçə» — and its reason on one muted line; the final
 * of the match's own two places (`finalRows()`), the winner's with
 * `Trophy`, a thrower's «−1» beside its points, with the final's confetti,
 * hits, roll, fanfare and cheer as after a race; then the board as it
 * ended with the last move marked, «Gedişlər», and the room's standing as
 * the plain card. A match the host stopped says «Oyun dayandırıldı» and
 * has no final. `big` is the screen's left column.
 */
export function MatchEnd({
    state,
    game,
    match,
    placeLabels,
    motion,
    big = false,
}: {
    state: RoomState;
    game: RoomGame;
    match: Over;
    placeLabels: string[];
    motion: RoomMotion;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const result = match.result;
    const stopped = result.outcome === 'none';
    const winner = match.sides.find((side) => side.color === result.outcome);
    const winnerName =
        winner === undefined
            ? null
            : (state.players.find((player) => player.id === winner.player_id)
                  ?.name ?? t('rooms.show.nameless'));
    const Icon = stopped
        ? CircleStop
        : result.outcome === 'draw'
          ? Handshake
          : Trophy;
    const heading = stopped
        ? t('rooms.match.stopped')
        : winnerName !== null
          ? t('rooms.match.won', { name: winnerName })
          : t('rooms.match.draw');

    const head = (
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
                    stopped
                        ? 'bg-muted text-muted-foreground'
                        : 'bg-amber-400 text-amber-950',
                )}
            >
                <Icon aria-hidden className={big ? 'size-[4.4vh]' : 'size-5'} />
            </span>
            <span className="flex min-w-0 flex-col">
                <span
                    className={cn(
                        'text-muted-foreground',
                        big ? 'text-[2.4vh]' : 'text-sm',
                    )}
                >
                    {t('rooms.game.over')} · {gameSummary(t, game)}
                </span>
                <span
                    className={cn(
                        'leading-tight font-bold',
                        big ? 'text-[4.4vh]' : 'text-lg',
                    )}
                >
                    {heading}
                </span>
                {!stopped && (
                    <span
                        className={cn(
                            'text-muted-foreground',
                            big ? 'text-[2.4vh]' : 'text-sm',
                        )}
                    >
                        {t(`rooms.match.ending.${result.ending}`)}
                    </span>
                )}
            </span>
        </div>
    );

    const board = (
        <DraughtsBoard
            position={match.position}
            last={match.last}
            flipped={
                !big &&
                match.sides.find(
                    (side) => side.player_id === state.me.player_id,
                )?.color === 'black'
            }
            labels={big ? 'outside' : 'inside'}
            className={big ? 'h-full' : undefined}
        />
    );

    const final = !stopped && (
        <FinalStanding
            ranking={finalRows(state)}
            players={state.players}
            me={big ? null : state.me.player_id}
            placeLabels={placeLabels}
            animate={motion.phase}
            forRoom={!big && carriesRoom(state)}
            spent={result.spent}
            big={big}
        />
    );

    if (big) {
        return (
            <div
                data-motion={motion.phase ? 'on' : undefined}
                className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_auto] gap-[3vw]"
            >
                <div className="flex min-w-0 flex-col gap-[2.4vh]">
                    {head}
                    {final}
                    <div className="mt-auto text-[max(0.875rem,1.8vh)]">
                        <StandingCard state={state} placeLabels={placeLabels} />
                    </div>
                </div>
                <div className="h-[min(50vh,22vw)]">{board}</div>
            </div>
        );
    }

    return (
        <section
            key={game.id}
            data-motion={motion.phase ? 'on' : undefined}
            className="flex flex-col gap-3"
        >
            {head}
            {final}
            <div className="-mx-2">{board}</div>
            <MoveListButton moves={match.moves} className="self-start" />
            <StandingCard state={state} placeLabels={placeLabels} />
        </section>
    );
}
