import { Handshake, Trophy } from 'lucide-react';
import { useMemo } from 'react';
import { CheckersBoard } from '@/components/rooms/checkers/checkers-board';
import {
    clockLabel,
    piecesOf,
    timeLeft,
} from '@/components/rooms/checkers/match';
import type {
    RoomMatch,
    RoomMatchState,
    Side,
} from '@/components/rooms/checkers/match';
import { useMatch, useTicking } from '@/components/rooms/checkers/use-match';
import { useMatchSound } from '@/components/rooms/checkers/use-match-sound';
import { RoomQr, spacedCode } from '@/components/rooms/room-code';
import type { JoinLink } from '@/components/rooms/room-code';
import { SoundToggle } from '@/components/rooms/sound-toggle';
import { StandingCard } from '@/components/rooms/start-parts';
import { ScreenTauntReaction } from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomPlayer, RoomState } from '@/rooms/contract';
import type { RoomSoundUrls } from '@/rooms/sound';

/**
 * A board room on the big screen (docs/frontend.md rule 15 "Board games",
 * ADR 034 decision 13): read-only — the host starts a match from the phone.
 * During a match the board from white's side at the screen's full height,
 * and beside it the two players with their pieces and clocks, whose turn
 * it is, the taunts thrown (with their targets, unless «Atmacalar» hides
 * them) and the room's standing; outside one the QR and the code, the last
 * result and the standing. The screen is the room's speaker: «go», each
 * move's «question» and the final's «fanfare».
 */
export function ScreenCheckers({
    roomId,
    state,
    initial,
    join,
    sounds,
    taunts,
    tauntsHidden,
    placeLabels,
}: {
    roomId: string;
    state: RoomState;
    initial: RoomMatchState | null;
    join: JoinLink | null;
    sounds: RoomSoundUrls;
    taunts: TauntList;
    tauntsHidden: boolean;
    placeLabels: string[];
}) {
    const { t } = useTranslation();
    const { view, serverNow } = useMatch({
        roomId,
        initial,
        revision: state.revision,
        screen: true,
    });
    const sound = useMatchSound({
        speaker: 'screen',
        sounds,
        view,
        carries: true,
    });
    const match = view?.match ?? null;
    const running = match !== null && !match.over;
    const now = useTicking(serverNow, running);
    const players = useMemo(
        () => new Map(state.players.map((player) => [player.id, player])),
        [state.players],
    );

    return (
        <main className="relative flex min-h-0 flex-1 items-center justify-center gap-[4vw] px-[4vw] py-[3vh]">
            <div className="absolute top-[2vh] right-[3vw]">
                <SoundToggle sound={sound} big />
            </div>
            {running && view !== null ? (
                <>
                    <ScreenTauntReaction
                        shown={view.taunts.shown}
                        players={state.players}
                        taunts={taunts}
                        now={now}
                        hidden={tauntsHidden}
                    />
                    <div className="h-[min(84vh,56vw)] w-[min(84vh,56vw)] shrink-0">
                        <CheckersBoard
                            board={match.board}
                            flipped={false}
                            lastPath={match.last_path}
                            selected={[]}
                            targets={new Set()}
                            starts={new Set()}
                            onTap={null}
                            fill
                        />
                    </div>
                    <div className="flex w-[min(34vw,32rem)] flex-col gap-[2vh]">
                        <ScreenSide
                            side="b"
                            match={match}
                            players={players}
                            ms={timeLeft(match, 'b', view.server_ms, now)}
                        />
                        <ScreenSide
                            side="w"
                            match={match}
                            players={players}
                            ms={timeLeft(match, 'w', view.server_ms, now)}
                        />
                        {state.ranking.length > 0 && (
                            <div className="text-[max(0.875rem,1.8vh)]">
                                <StandingCard
                                    state={state}
                                    placeLabels={placeLabels}
                                />
                            </div>
                        )}
                    </div>
                </>
            ) : (
                <>
                    {join !== null && (
                        <RoomQr
                            url={join.url}
                            size={512}
                            fluid
                            className="w-[min(52vh,30vw)] p-[1.4vh]"
                        />
                    )}
                    <div className="flex min-w-0 flex-col gap-[1.6vh]">
                        <span className="text-[max(1.25rem,3.6vh)] font-semibold">
                            {t('rooms.boards.checkers.name')}
                        </span>
                        {match !== null && match.over && (
                            <ScreenResult match={match} players={players} />
                        )}
                        {state.ranking.length > 0 && (
                            <div className="w-[min(40vw,36rem)] text-[max(0.875rem,1.8vh)]">
                                <StandingCard
                                    state={state}
                                    placeLabels={placeLabels}
                                />
                            </div>
                        )}
                        <span className="text-[max(1rem,2.8vh)] text-muted-foreground">
                            {t('rooms.show.code')}
                        </span>
                        {state.room.code !== null && (
                            <span className="text-[min(15vh,10vw)] leading-none font-bold tracking-wider whitespace-nowrap tabular-nums">
                                {spacedCode(state.room.code)}
                            </span>
                        )}
                        <span className="max-w-[44vw] text-[max(0.9375rem,2.6vh)] text-balance text-muted-foreground">
                            {t('rooms.match.host_starts')}
                        </span>
                    </div>
                </>
            )}
        </main>
    );
}

function ScreenSide({
    side,
    match,
    players,
    ms,
}: {
    side: Side;
    match: RoomMatch;
    players: Map<string, RoomPlayer>;
    ms: number;
}) {
    const { t } = useTranslation();
    const toMove = match.turn === side;
    const id = side === 'w' ? match.white : match.black;

    return (
        <div
            className={cn(
                'flex items-center gap-[1.4vw] rounded-[2vh] border-2 px-[1.6vw] py-[1.8vh]',
                toMove ? 'border-primary bg-accent' : 'bg-card',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'size-[5vh] shrink-0 rounded-full border-[0.6vh]',
                    side === 'w'
                        ? 'border-stone-300 bg-stone-50'
                        : 'border-neutral-700 bg-neutral-900',
                )}
            />
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-[max(1.125rem,3.2vh)] font-semibold">
                    {players.get(id)?.name ?? t('rooms.show.nameless')}
                </span>
                <span className="text-[max(0.875rem,2.2vh)] text-muted-foreground">
                    {t('rooms.match.pieces', {
                        count: piecesOf(match.board, side),
                    })}
                    {toMove && ` · ${t('rooms.match.to_move')}`}
                </span>
            </span>
            <span
                className={cn(
                    'shrink-0 rounded-[1vh] px-[1vh] font-mono text-[max(1.5rem,5vh)] font-bold tabular-nums',
                    toMove ? 'bg-primary text-primary-foreground' : 'bg-muted',
                    toMove && ms <= 30_000 && 'bg-red-600 text-white',
                )}
            >
                {clockLabel(ms)}
            </span>
        </div>
    );
}

function ScreenResult({
    match,
    players,
}: {
    match: RoomMatch;
    players: Map<string, RoomPlayer>;
}) {
    const { t } = useTranslation();
    const nameless = t('rooms.show.nameless');
    const winner =
        match.result === 'w' || match.result === 'b'
            ? (players.get(match.result === 'w' ? match.white : match.black)
                  ?.name ?? nameless)
            : null;

    return (
        <div className="flex items-center gap-[1.4vw] rounded-[2vh] border-2 border-primary bg-card px-[1.6vw] py-[1.8vh]">
            {match.result === 'draw' ? (
                <Handshake className="size-[6vh] shrink-0 text-primary" />
            ) : (
                <Trophy className="size-[6vh] shrink-0 text-amber-500" />
            )}
            <span className="flex min-w-0 flex-col">
                <span className="truncate text-[max(1.25rem,3.6vh)] font-semibold">
                    {winner !== null
                        ? t('rooms.match.won', { name: winner })
                        : match.result === 'draw'
                          ? t('rooms.match.draw')
                          : t('rooms.match.ended')}
                </span>
                {match.ending !== null && (
                    <span className="text-[max(0.875rem,2.2vh)] text-muted-foreground">
                        {t(`rooms.match.ending.${match.ending}`)}
                    </span>
                )}
            </span>
        </div>
    );
}
