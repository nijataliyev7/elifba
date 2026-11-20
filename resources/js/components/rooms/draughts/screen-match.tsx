import { DraughtsBoard } from '@/components/rooms/draughts/draughts-board';
import { MatchLine } from '@/components/rooms/draughts/match-line';
import { MatchStrip } from '@/components/rooms/draughts/match-strip';
import { MoveLines } from '@/components/rooms/draughts/move-list';
import { useArrival } from '@/components/rooms/draughts/use-arrival';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import { ScreenTauntReaction } from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import type { RoomMotion } from '@/components/rooms/use-motion';
import { useServerNow } from '@/components/rooms/use-server-now';
import { useTranslation } from '@/hooks/use-translation';
import type { ServerClock } from '@/rooms/clock';
import type { RoomGame, RoomMatch, RoomState } from '@/rooms/contract';

/**
 * A live match on the big screen (docs/frontend.md rule 15 "Board games"
 * → "The screen", ADR 034 decision 27): the rivalry strip of white and
 * black with their clocks, in `vh`; under it the board at the height that
 * is left, from white's side with the files and ranks outside it, and on
 * its right a column with the moves («Gedişlər», always shown), whose turn
 * it is and a standing offer; a taunt's reaction over the whole screen as
 * in a round. It writes nothing and moves only what it saw arrive.
 */
export function ScreenMatch({
    state,
    game,
    match,
    clock,
    placeLabels,
    motion,
    icons,
    taunts,
    tauntsHidden,
}: {
    state: RoomState;
    game: Extract<RoomGame, { kind: 'board' }>;
    match: RoomMatch;
    clock: ServerClock;
    placeLabels: string[];
    motion: RoomMotion;
    icons: RivalryIcons;
    taunts: TauntList;
    tauntsHidden: boolean;
}) {
    const { t } = useTranslation();
    const now = useServerNow(clock, true);
    const arrival = useArrival(game.id, match);

    return (
        <section
            data-phase={match.phase}
            data-ply={match.ply}
            className="flex min-h-0 flex-1 flex-col gap-[2.4vh]"
        >
            <MatchStrip
                state={state}
                match={match}
                now={now}
                icons={icons}
                placeLabels={placeLabels}
                moves={motion.strip && arrival !== null}
                big
            />

            <ScreenTauntReaction
                shown={state.taunts?.shown}
                players={state.players}
                taunts={taunts}
                now={now}
                hidden={tauntsHidden}
            />

            <div className="flex min-h-0 flex-1 justify-center gap-[3vw]">
                <DraughtsBoard
                    position={match.position}
                    last={match.last}
                    flipped={false}
                    arrival={arrival}
                    labels="outside"
                    className="h-full max-w-[60vw]"
                />
                <aside className="flex min-h-0 w-[min(28vw,30rem)] flex-col gap-[1.6vh]">
                    <h2 className="text-[max(1rem,2.6vh)] font-semibold">
                        {t('rooms.match.moves')}
                    </h2>
                    <MoveLines
                        moves={match.moves}
                        big
                        className="min-h-0 flex-1 rounded-2xl border bg-card p-[1.6vh]"
                    />
                    <MatchLine
                        match={match}
                        seat={null}
                        players={state.players}
                        now={now}
                        big
                    />
                </aside>
            </div>
        </section>
    );
}
