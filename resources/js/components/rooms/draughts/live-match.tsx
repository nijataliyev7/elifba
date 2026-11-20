import type { ReactNode } from 'react';
import { clockLevelText } from '@/components/rooms/catalog';
import { seatOf } from '@/components/rooms/draughts/board';
import { DraughtsBoard } from '@/components/rooms/draughts/draughts-board';
import { MatchActions } from '@/components/rooms/draughts/match-actions';
import { MatchLine } from '@/components/rooms/draughts/match-line';
import { MatchStrip, rivalOf } from '@/components/rooms/draughts/match-strip';
import { useArrival } from '@/components/rooms/draughts/use-arrival';
import { useMatchActs } from '@/components/rooms/draughts/use-match-acts';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import { RoomActionBar } from '@/components/rooms/room-action-bar';
import { TauntReaction, TauntThrow } from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import type { RoomMotion } from '@/components/rooms/use-motion';
import type { RoomSound } from '@/components/rooms/use-room-sound';
import { useServerNow } from '@/components/rooms/use-server-now';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { ServerClock } from '@/rooms/clock';
import type { RoomGame, RoomMatch, RoomState } from '@/rooms/contract';

type BoardGame = Extract<RoomGame, { kind: 'board' }>;

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
    tauntsHidden,
    onTauntsHiddenChange,
    footer,
}: {
    roomId: string;
    state: RoomState;
    game: BoardGame;
    match: RoomMatch;
    clock: ServerClock;
    placeLabels: string[];
    refresh: () => void;
    onGone: () => void;
    motion: RoomMotion;
    sound: RoomSound | null;
    icons: RivalryIcons | null;
    taunts: TauntList | null;
    tauntsHidden: boolean;
    onTauntsHiddenChange: (hidden: boolean) => void;
    footer?: ReactNode;
}) {
    const now = useServerNow(clock, true);
    const seat = seatOf(match, state.me.player_id);
    const arrival = useArrival(game.id, match);
    const acts = useMatchActs({ roomId, match, clock, refresh, onGone, sound });
    // The viewer's own move is already standing where it went: only the other seat's slides.
    const own =
        arrival !== null &&
        seat !== null &&
        arrival.last.path.join() === acts.sentPath?.join();
    const offered =
        match.phase === 'play' &&
        match.offer !== null &&
        seat !== null &&
        seat.color !== match.offer
            ? (state.players.find(
                  (player) =>
                      player.id ===
                      match.sides.find((side) => side.color === match.offer)
                          ?.player_id,
              )?.name ?? null)
            : null;

    return (
        <>
            <div
                data-phase={match.phase}
                data-ply={match.ply}
                className="mx-auto flex w-full max-w-xl flex-col gap-3 px-4 pt-3 pb-2 md:px-6"
            >
                <MatchStrip
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
                        rival={rivalOf(state, match)}
                        list={taunts}
                        clock={clock}
                        now={now}
                        phase={`match:${game.id}`}
                        hidden={tauntsHidden}
                        onHiddenChange={onTauntsHiddenChange}
                        onThrown={refresh}
                        noPoints="rooms.taunts.none_left"
                    />
                </MatchStrip>

                <TauntReaction
                    received={state.taunts?.received}
                    players={state.players}
                    list={taunts}
                    now={now}
                    hidden={tauntsHidden}
                />

                <div className="-mx-2 sm:mx-auto sm:w-full sm:max-w-[480px]">
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

                <MatchLine
                    match={match}
                    seat={seat}
                    players={state.players}
                    now={now}
                    begun={acts.taps.length > 0}
                    failed={acts.failed}
                    onUndo={acts.undo}
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
 * A match's title in the game frame's top bar (ADR 032 decision 4): the
 * page's `h1` «Şaşki» over «5+3 · :number-ci gediş», the move's number as
 * the record counts it.
 */
export function MatchTitle({
    game,
    match,
    motion = false,
}: {
    game: BoardGame;
    match: RoomMatch;
    motion?: boolean;
}) {
    const { t } = useTranslation();
    const number = Math.floor(match.ply / 2) + 1;

    return (
        <>
            <h1
                id="round-heading"
                className={cn(
                    'origin-left truncate text-base leading-tight font-semibold',
                    motion && 'motion-safe:animate-room-pop',
                )}
            >
                {t(`rooms.mechanics.${game.mechanic}.name`)}
            </h1>
            <p className="truncate text-xs text-muted-foreground tabular-nums">
                {clockLevelText(t, game.clock)}
                {' · '}
                {t('rooms.match.move_number', { number })}
            </p>
        </>
    );
}
