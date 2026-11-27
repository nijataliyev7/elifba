import { Head } from '@inertiajs/react';
import { Bot } from 'lucide-react';
import type { ReactNode } from 'react';
import { gameLabel } from '@/components/rooms/catalog';
import { computerOf } from '@/components/rooms/computer';
import { DominoHand } from '@/components/rooms/domino/domino-hand';
import { DominoLine } from '@/components/rooms/domino/domino-line';
import { DominoStrip } from '@/components/rooms/domino/domino-strip';
import { DominoTable } from '@/components/rooms/domino/domino-table';
import type { DominoArrival } from '@/components/rooms/domino/domino-table';
import { HandSheet } from '@/components/rooms/domino/hand-sheet';
import {
    dealtNow,
    PHONE_PX,
    tableArrival,
} from '@/components/rooms/domino/stage';
import { DominoDefs } from '@/components/rooms/domino/tile';
import {
    AFTER_STEPS_MS,
    turnSteps,
    viewerSeat,
    yourPlay,
} from '@/components/rooms/domino/turn';
import { useDominoActs } from '@/components/rooms/domino/use-domino-acts';
import { FrameTitle, ScreenHead } from '@/components/rooms/game-head';
import { Countdown } from '@/components/rooms/live-round';
import { rivalOf } from '@/components/rooms/rivalry';
import { useRivalry } from '@/components/rooms/rivalry-strip';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import { TauntReaction, TauntThrow } from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import { useArrival } from '@/components/rooms/use-arrival';
import type { RoomMotion } from '@/components/rooms/use-motion';
import type { RoomSound } from '@/components/rooms/use-room-sound';
import { useServerNow } from '@/components/rooms/use-server-now';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { useWakeLock } from '@/hooks/use-wake-lock';
import type { ServerClock } from '@/rooms/clock';
import type { RoomDomino, RoomDominoGame, RoomState } from '@/rooms/contract';

/**
 * The act a page saw arrive at `domino`'s ply (the rooms' one
 * `useArrival()`): a play flies from its seat, a «Sən aç» moves nothing;
 * null on a page opened on the position, a state more than one ply ahead
 * and a state fetched again.
 */
export function useDominoArrival(
    game: string,
    domino: RoomDomino,
): DominoArrival | null {
    return useArrival(game, domino.ply, null, () =>
        domino.last === null
            ? null
            : {
                  seat: domino.last.seat,
                  tile: domino.last.kind === 'play' ? domino.last.tile : null,
                  key: `${game}:${domino.ply}`,
              },
    );
}

/** Whether the page saw the hand it shows open: its number one more than the hand it drew (the deal). */
export function useDealt(game: string, domino: RoomDomino): boolean {
    return (
        useArrival(game, domino.hand?.number ?? 0, null, () => true) === true
    );
}

/**
 * A live domino game on a seat's phone (docs/frontend.md rule 15 "Domino"
 * → "A game on a seat's phone", ADR 038 decisions 44–53), in the game
 * frame, top to bottom at 375 × 667 with nothing to scroll: the strip of
 * the account (99px with its gap) — its rival half a seat's throw —, the
 * table, which takes what is left and is never under 200px tall, the line
 * under it (44px) and the hand (108px a row). There is no action bar: no
 * «Bazar» or «Pas» to tap; the host's «Oyunu bitir» (`footer`) stands
 * below the fold. A watcher and a host who does not sit have no hand: the
 * table takes its place. In the pause after a hand the sheet rises over
 * the table and the hand by itself.
 *
 * It draws the one state `useLiveRoom()` follows: it offers only
 * `mine.legal`, posts a seat's acts (`useDominoActs()`) and moves only what
 * it saw arrive (`useArrival()`); its sounds are the page's (`roomCues()`
 * and `sound.tap`). On the viewer's own turn (`yourPlay()`) the line is
 * the band and the tab's title says «● Sizin növbənizdir»; a seat's phone
 * holds a wake lock while its game is live. A seat played for while its
 * player is away shows the away card over its dimmed tiles.
 */
export function LiveDomino({
    roomId,
    state,
    game,
    domino,
    clock,
    placeLabels,
    refresh,
    onGone,
    motion,
    sound,
    icons,
    taunts,
    footer,
}: {
    roomId: string;
    state: RoomState;
    game: RoomDominoGame;
    domino: RoomDomino;
    clock: ServerClock;
    placeLabels: string[];
    refresh: () => void;
    onGone: () => void;
    motion: RoomMotion;
    sound: RoomSound | null;
    icons: RivalryIcons | null;
    taunts: TauntList | null;
    footer?: ReactNode;
}) {
    const { t } = useTranslation();
    const now = useServerNow(clock, true);
    const viewer = state.me.player_id;
    const seat = viewerSeat(domino, viewer);
    const turn = yourPlay(domino, viewer);
    const arrival = useDominoArrival(game.id, domino);
    // Only in the moment the hand opens: a tile that comes later is drawn as what it is (ADR 038 "As built (Y7)").
    const dealt = dealtNow(useDealt(game.id, domino), domino) && motion.phase;
    const rivalry = useRivalry(state, viewer);
    const acts = useDominoActs({
        roomId,
        gameId: game.id,
        domino,
        clock,
        refresh,
        onGone,
        sound,
    });
    // The viewer's own play already stands at its end: only another seat's flies, but what followed it is drawn here too.
    const own =
        arrival !== null &&
        acts.posted !== null &&
        acts.posted.ply + 1 === domino.ply &&
        acts.posted.path[0] === arrival.tile;
    const steps = arrival === null ? null : turnSteps(domino);
    const drawAt =
        steps?.steps.find((step) => step.kind === 'draw' && step.seat === seat)
            ?.at ?? null;
    const mine = domino.mine;

    useWakeLock(seat !== null && domino.phase !== 'over');

    return (
        <>
            {turn && <Head title={t('rooms.match.turn_title')} />}
            <DominoDefs />
            <div
                data-phase={domino.phase}
                data-ply={domino.ply}
                data-hand={domino.hand?.number}
                data-motion={motion.phase ? 'on' : undefined}
                className="relative mx-auto flex min-h-[29rem] w-full max-w-xl flex-col"
                // The frame's top bar is 56px and its 1px border: nothing scrolls at 375 × 667.
                style={{
                    height: `calc(100svh - ${PHONE_PX.bar}px - env(safe-area-inset-top))`,
                }}
            >
                {/* The strip, 99px with its gap (docs/frontend.md rule 15 "Domino"): 8 above it, 4 under it. */}
                <div className="shrink-0 px-4 pt-2 pb-1">
                    <DominoStrip
                        rivalry={rivalry}
                        state={state}
                        game={game}
                        domino={domino}
                        viewer={viewer}
                        placeLabels={placeLabels}
                        icons={icons}
                        moves={motion.strip}
                    >
                        <TauntThrow
                            roomId={roomId}
                            state={state}
                            rival={rivalOf(rivalry.pair, state.players, domino)}
                            list={taunts}
                            clock={clock}
                            now={now}
                            phase={`domino:${game.id}:${domino.ply}`}
                            onThrown={refresh}
                            noPoints="rooms.taunts.none_left"
                        />
                    </DominoStrip>
                </div>

                <TauntReaction
                    received={state.taunts?.received}
                    players={state.players}
                    list={taunts}
                    now={now}
                />

                <div className="relative flex min-h-[200px] flex-1 flex-col px-2">
                    <DominoTable
                        domino={domino}
                        table={game.table}
                        players={state.players}
                        computer={computerOf(game)}
                        viewer={viewer}
                        now={now}
                        targets={acts.targets}
                        onTarget={acts.tapTarget}
                        pending={acts.pending}
                        arrival={tableArrival(arrival, own)}
                        className="flex-1"
                    />
                    {domino.phase === 'countdown' && (
                        <div
                            key={`countdown:${game.id}`}
                            className="pointer-events-none absolute inset-x-2 top-0 bottom-0 z-10 flex items-center justify-center rounded-xl bg-background/85 backdrop-blur-[2px]"
                        >
                            <Countdown
                                game={game}
                                until={domino.starts_ms}
                                now={now}
                            />
                        </div>
                    )}
                </div>

                <div className="shrink-0 px-4">
                    <DominoLine
                        domino={domino}
                        game={game}
                        players={state.players}
                        viewer={viewer}
                        now={now}
                        picking={
                            acts.picked !== null && acts.targets.length > 0
                        }
                        failed={acts.failed}
                        mayYield={turn && (mine?.may_yield ?? false)}
                        busy={acts.busy}
                        onUndo={acts.putBack}
                        onYield={acts.yieldOpening}
                        motion={motion.phase}
                        arrived={arrival !== null}
                    />
                </div>

                {mine !== null && (
                    <div className="relative shrink-0">
                        <DominoHand
                            key={`hand:${domino.hand?.number ?? 0}`}
                            tiles={
                                acts.pending === null
                                    ? mine.tiles
                                    : mine.tiles.filter(
                                          (tile) => tile !== acts.pending?.[0],
                                      )
                            }
                            lit={acts.lit}
                            turn={turn}
                            picked={acts.picked}
                            onTap={acts.tapTile}
                            drawn={mine.drawn}
                            drawAt={motion.phase ? drawAt : null}
                            dealt={dealt}
                            dimmed={mine.away}
                        />
                        {mine.away && (
                            <div className="absolute inset-0 z-10 flex items-center justify-center px-4">
                                <div className="flex w-full flex-col items-center gap-2 rounded-2xl border bg-card/95 p-3 text-center shadow-lg backdrop-blur-[2px]">
                                    <p className="flex items-center gap-1.5 text-sm font-medium">
                                        <Bot
                                            aria-hidden
                                            className="size-4 shrink-0"
                                        />
                                        {t('rooms.domino.away')}
                                    </p>
                                    <Button
                                        type="button"
                                        className="h-14 w-full text-base"
                                        disabled={acts.busy}
                                        onClick={acts.comeBack}
                                    >
                                        {acts.busy && <Spinner />}
                                        {t('rooms.domino.back')}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {domino.phase === 'sheet' && (
                    <div className="absolute inset-x-0 top-[6.25rem] bottom-0 z-30 flex flex-col justify-end">
                        <HandSheet
                            key={`sheet:${domino.sheet.hand}`}
                            domino={domino}
                            target={game.target}
                            players={state.players}
                            computer={computerOf(game)}
                            viewer={viewer}
                            now={now}
                            rises={
                                arrival !== null && steps !== null
                                    ? steps.ends + AFTER_STEPS_MS
                                    : null
                            }
                        />
                    </div>
                )}
            </div>
            {footer !== undefined && footer !== null && footer !== false && (
                <div className="mx-auto w-full max-w-xl px-4 pb-6">
                    {footer}
                </div>
            )}
        </>
    );
}

/**
 * A domino game's title (ADR 032 decision 4): in the game frame's top bar
 * the page's `h1` «101» over «30 san · Əl :number» — no ordinal suffix —;
 * on the screen (`big`), as a round's head stands there, «101 · 30 san ·
 * Para 2×2» muted over «Əl :number».
 */
export function DominoTitle({
    game,
    domino,
    motion = false,
    big = false,
}: {
    game: RoomDominoGame;
    domino: RoomDomino;
    motion?: boolean;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const name = t(`rooms.mechanics.${game.mechanic}.name`);
    const turn = t('rooms.turn.level', {
        seconds: Math.round(game.turn_ms / 1000),
    });
    const hand =
        domino.hand === null
            ? null
            : t('rooms.domino.hand_number', { number: domino.hand.number });

    if (!big) {
        return (
            <FrameTitle
                title={name}
                titleKey={game.id}
                line={hand === null ? turn : `${turn} · ${hand}`}
                motion={motion && domino.phase === 'countdown'}
            />
        );
    }

    return (
        <ScreenHead
            line={gameLabel(t, game)}
            title={hand ?? name}
            titleKey={domino.hand?.number ?? 0}
            motion={motion}
        />
    );
}
