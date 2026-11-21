import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { clockLevelText } from '@/components/rooms/catalog';
import { offerTo, seatOf, yourTurn } from '@/components/rooms/draughts/board';
import { DraughtsBoard } from '@/components/rooms/draughts/draughts-board';
import { MatchActions } from '@/components/rooms/draughts/match-actions';
import { MatchLine } from '@/components/rooms/draughts/match-line';
import { MatchStrip } from '@/components/rooms/draughts/match-strip';
import {
    ownArrival,
    useArrival,
} from '@/components/rooms/draughts/use-arrival';
import { useMatchActs } from '@/components/rooms/draughts/use-match-acts';
import { rivalOf } from '@/components/rooms/rivalry';
import { useRivalry } from '@/components/rooms/rivalry-strip';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import { FrameTitle, ScreenHead } from '@/components/rooms/game-head';
import { Countdown } from '@/components/rooms/live-round';
import { RoomActionBar } from '@/components/rooms/room-action-bar';
import { TauntReaction, TauntThrow } from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import type { RoomMotion } from '@/components/rooms/use-motion';
import type { RoomSound } from '@/components/rooms/use-room-sound';
import { useServerNow } from '@/components/rooms/use-server-now';
import { useTranslation } from '@/hooks/use-translation';
import { useWakeLock } from '@/hooks/use-wake-lock';
import type { ServerClock } from '@/rooms/clock';
import type { RoomBoardGame, RoomMatch, RoomState } from '@/rooms/contract';

/**
 * A live match on a phone (docs/frontend.md rule 15 "Board games" → "A
 * match on the phone", ADR 034 decision 27), in the game frame, top to
 * bottom at 375 × 667 with nothing to scroll: the rivalry strip of the two
 * seats with the clocks (`MatchStrip`) — the rival's half a seat's throw,
 * as T4 built it — the board (`DraughtsBoard`, turned round for black),
 * one line of whose turn it is (`MatchLine`) and the action bar
 * (`MatchActions`). It draws the one state `useLiveRoom()` follows: it
 * offers only the state's paths, posts a seat's acts (`useMatchActs()`) and
 * moves only what it saw arrive (`useArrival()`); its sounds are the
 * page's (`roomCues()` and `sound.tap`). A taunt aimed at the viewer is
 * its reaction in the middle of the viewport, as in a round. `footer`
 * stands under the line: the host's «Oyunu bitir».
 *
 * A seat knows whose turn it is without reading (ADR 034 "Amended
 * 2026-11-21 — whose turn it is"): on its turn (`yourTurn()`) the line is
 * a band and its clock is ringed, its sounds and its one vibration are the
 * page's (`roomCues()`), and the tab's title says «● Sizin növbənizdir»;
 * its phone holds a screen wake lock while the match is live, so it does
 * not sleep while the other seat thinks. A watcher's page has none of it.
 */
export function LiveMatch({
    roomId,
    state,
    game,
    match,
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
    game: RoomBoardGame;
    match: RoomMatch;
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
    const seat = seatOf(match, state.me.player_id);
    const turn = yourTurn(match, state.me.player_id);
    const arrival = useArrival(game.id, match);
    const rivalry = useRivalry(state, state.me.player_id);
    const acts = useMatchActs({
        roomId,
        gameId: game.id,
        match,
        clock,
        refresh,
        onGone,
        sound,
    });
    // The viewer's own move is already standing where it went: only the other seat's slides.
    const own = seat !== null && ownArrival(arrival, acts.posted, match.ply);
    const offerer = offerTo(match, state.me.player_id);
    // A nameless seat's offer still has its card.
    const offered =
        offerer === null
            ? null
            : (state.players.find((player) => player.id === offerer.player_id)
                  ?.name ?? t('rooms.show.nameless'));

    // A seat's phone stays awake through the countdown and the play.
    useWakeLock(seat !== null && match.phase !== 'over');

    return (
        <>
            {/* While it is the viewer's turn the tab says so; the room's title comes back when it passes. */}
            {turn && <Head title={t('rooms.match.turn_title')} />}
            <div
                data-phase={match.phase}
                data-ply={match.ply}
                className="mx-auto flex w-full max-w-xl flex-col gap-3 px-4 pt-3 pb-2 md:px-6"
            >
                <MatchStrip
                    rivalry={rivalry}
                    state={state}
                    match={match}
                    now={now}
                    icons={icons}
                    placeLabels={placeLabels}
                    moves={motion.strip && arrival !== null}
                >
                    <TauntThrow
                        roomId={roomId}
                        state={state}
                        rival={rivalOf(rivalry.pair, state.players)}
                        list={taunts}
                        clock={clock}
                        now={now}
                        phase={`match:${game.id}:${match.ply}`}
                        onThrown={refresh}
                        noPoints="rooms.taunts.none_left"
                    />
                </MatchStrip>

                <TauntReaction
                    received={state.taunts?.received}
                    players={state.players}
                    list={taunts}
                    now={now}
                />

                <div className="-mx-2 sm:mx-auto sm:w-full sm:max-w-[480px]">
                    {/* No taller than the viewport leaves — 19.25rem: the frame's bar, the strip, the line and the action bar — and never under 44px squares: 22rem and the rim's 3px a side. */}
                    <div className="relative mx-auto max-w-[max(calc(22rem+6px),calc(100svh-19.25rem))]">
                        {/* The countdown over the board, as a race's first round opens (ADR 034 as amended 2026-11-21). */}
                        {match.phase === 'countdown' && (
                            <div
                                key={`countdown:${game.id}`}
                                data-motion={motion.phase ? 'on' : undefined}
                                className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-background/85 backdrop-blur-[2px]"
                            >
                                <Countdown
                                    game={game}
                                    until={match.starts_ms}
                                    now={now}
                                />
                            </div>
                        )}
                        <DraughtsBoard
                            position={match.position}
                            last={match.last}
                            flipped={seat?.color === 'black'}
                            arrival={own ? null : arrival}
                            taps={acts.taps}
                            marks={acts.marks}
                            pending={acts.pending}
                            onTap={acts.tap}
                        />
                    </div>
                </div>

                <MatchLine
                    match={match}
                    seat={seat}
                    players={state.players}
                    now={now}
                    begun={acts.taps.length > 0}
                    failed={acts.failed}
                    onUndo={acts.undo}
                    motion={motion.phase}
                />

                {footer}
            </div>

            <RoomActionBar framed>
                <MatchActions
                    match={match}
                    seat={seat}
                    offerFrom={offered}
                    acts={acts}
                />
            </RoomActionBar>
        </>
    );
}

/**
 * A match's title (ADR 032 decision 4): in the game frame's top bar the
 * page's `h1` «Şaşki» over «5+3 · Gediş :number»; on the screen (`big`),
 * as a round's head stands there, «Şaşki · 5+3» muted over «Gediş
 * :number» — the move's number as the record counts it.
 */
export function MatchTitle({
    game,
    match,
    motion = false,
    big = false,
}: {
    game: RoomBoardGame;
    match: RoomMatch;
    motion?: boolean;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const name = t(`rooms.mechanics.${game.mechanic}.name`);
    const clock = clockLevelText(t, game.clock);
    const move = t('rooms.match.move_number', {
        number: Math.floor(match.ply / 2) + 1,
    });

    if (!big) {
        return (
            <FrameTitle
                title={name}
                titleKey={game.id}
                line={`${clock} · ${move}`}
                // «Şaşki» pops in once, at the match's start the page saw — not at a later move.
                motion={motion && match.ply === 0}
            />
        );
    }

    return (
        <ScreenHead
            line={`${name} · ${clock}`}
            title={move}
            titleKey={match.ply}
            motion={motion}
        />
    );
}
