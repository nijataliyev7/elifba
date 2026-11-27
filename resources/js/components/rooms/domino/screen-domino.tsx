import { computerOf } from '@/components/rooms/computer';
import { DominoLine } from '@/components/rooms/domino/domino-line';
import { DominoStrip } from '@/components/rooms/domino/domino-strip';
import { DominoTable } from '@/components/rooms/domino/domino-table';
import { HandSheet } from '@/components/rooms/domino/hand-sheet';
import {
    DominoTitle,
    useDominoArrival,
} from '@/components/rooms/domino/live-domino';
import { DominoDefs } from '@/components/rooms/domino/tile';
import { AFTER_STEPS_MS, turnSteps } from '@/components/rooms/domino/turn';
import { Countdown } from '@/components/rooms/live-round';
import { useRivalry } from '@/components/rooms/rivalry-strip';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import { ScreenFit } from '@/components/rooms/screen-fit';
import { ScreenTauntReaction } from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import type { RoomMotion } from '@/components/rooms/use-motion';
import { useServerNow } from '@/components/rooms/use-server-now';
import type { ServerClock } from '@/rooms/clock';
import type { RoomDomino, RoomDominoGame, RoomState } from '@/rooms/contract';

/**
 * A live domino game on the big screen (docs/frontend.md rule 15 "Domino"
 * → "The screen", ADR 038 decision 45): the strip in `vh`, the head —
 * «101 · 30 san · Para 2×2» over «Əl :number» — and the line of whose turn
 * it is, then the table at the height that is left: the line large,
 * turning inside it, the seats on its four sides with their names large,
 * their tile backs, the turn's ring and seconds, «PAS», `Bot`, and the
 * boneyard at one corner. Never a hand: the screen has no tiles to show,
 * whoever's account it reads with. The sheet opens over the table by
 * itself; a taunt's reaction covers the whole screen. It writes nothing
 * and moves only what it saw arrive.
 */
export function ScreenDomino({
    state,
    game,
    domino,
    clock,
    placeLabels,
    motion,
    icons,
    taunts,
    tauntsHidden,
}: {
    state: RoomState;
    game: RoomDominoGame;
    domino: RoomDomino;
    clock: ServerClock;
    placeLabels: string[];
    motion: RoomMotion;
    icons: RivalryIcons;
    taunts: TauntList;
    tauntsHidden: boolean;
}) {
    const now = useServerNow(clock, true);
    const arrival = useDominoArrival(game.id, domino);
    const rivalry = useRivalry(state, null);

    return (
        <section
            data-phase={domino.phase}
            data-ply={domino.ply}
            data-hand={domino.hand?.number}
            data-motion={motion.phase ? 'on' : undefined}
            className="flex min-h-0 flex-1 flex-col gap-[2vh]"
        >
            <DominoDefs />
            <DominoStrip
                rivalry={rivalry}
                state={state}
                game={game}
                domino={domino}
                viewer={null}
                placeLabels={placeLabels}
                icons={icons}
                moves={motion.strip}
                big
            />

            <ScreenTauntReaction
                shown={state.taunts?.shown}
                players={state.players}
                taunts={taunts}
                now={now}
                hidden={tauntsHidden}
            />

            <div className="flex items-end justify-between gap-[3vw]">
                <DominoTitle
                    game={game}
                    domino={domino}
                    motion={motion.round}
                    big
                />
                <div className="min-w-0">
                    <DominoLine
                        domino={domino}
                        game={game}
                        players={state.players}
                        viewer={null}
                        now={now}
                        motion={motion.phase}
                        arrived={arrival !== null}
                        big
                    />
                </div>
            </div>

            <div className="relative flex min-h-0 flex-1">
                <DominoTable
                    domino={domino}
                    table={game.table}
                    players={state.players}
                    computer={computerOf(game)}
                    viewer={null}
                    now={now}
                    arrival={arrival}
                    big
                    className="flex-1"
                />
                {domino.phase === 'countdown' && (
                    <div
                        key={`countdown:${game.id}`}
                        className="pointer-events-none absolute inset-0 z-10 flex flex-col rounded-[2.4vh] bg-background/85 backdrop-blur-[3px]"
                    >
                        <ScreenFit className="flex-1">
                            <Countdown
                                game={game}
                                until={domino.starts_ms}
                                now={now}
                                big
                            />
                        </ScreenFit>
                    </div>
                )}
                {domino.phase === 'sheet' && (
                    <div className="absolute inset-0 z-20 flex flex-col items-center p-[3vh]">
                        {/* Fitted to the table it covers: nobody scrolls a screen on a wall. */}
                        <ScreenFit className="h-full w-[min(72vw,64rem)]">
                            <div data-sheet-panel className="my-auto">
                                <HandSheet
                                    key={`sheet:${domino.sheet.hand}`}
                                    domino={domino}
                                    target={game.target}
                                    players={state.players}
                                    computer={computerOf(game)}
                                    viewer={null}
                                    now={now}
                                    rises={
                                        arrival !== null
                                            ? turnSteps(domino).ends +
                                              AFTER_STEPS_MS
                                            : null
                                    }
                                    big
                                />
                            </div>
                        </ScreenFit>
                    </div>
                )}
            </div>
        </section>
    );
}
