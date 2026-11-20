import { Form, Head, Link, router } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    CircleStop,
    Hourglass,
    LogOut,
    Play,
    RotateCcw,
    SlidersHorizontal,
    Trash2,
    Trophy,
    UserMinus,
    UserPlus,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { withGame } from '@/components/rooms/catalog';
import { CheckersRoom } from '@/components/rooms/checkers/checkers-room';
import type { RoomMatchState } from '@/components/rooms/checkers/match';
import type { GameSetupOptions } from '@/components/rooms/catalog';
import { FinalStanding } from '@/components/rooms/final-standing';
import { carriesRoom } from '@/components/rooms/sound-cues';
import { GameDrawing } from '@/components/rooms/game-drawing';
import {
    GameSettings,
    useRoomChoice,
    useRoomStart,
} from '@/components/rooms/game-settings';
import type { RoomChoice } from '@/components/rooms/game-settings';
import { LiveRound, RoundTitle } from '@/components/rooms/live-round';
import { RoomActionBar } from '@/components/rooms/room-action-bar';
import { CodeChip, CodeDialog, InviteCard } from '@/components/rooms/room-code';
import type { JoinLink } from '@/components/rooms/room-code';
import { RoomConfirm } from '@/components/rooms/room-confirm';
import {
    FrameTitle,
    LockedBadge,
    RoomFrame,
} from '@/components/rooms/room-frame';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import { tauntVoices, useTauntsHidden } from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import { RoomTools } from '@/components/rooms/room-tools';
import { SoundToggle } from '@/components/rooms/sound-toggle';
import { useTauntsSwitch } from '@/components/rooms/taunts-switch';
import { Standing } from '@/components/rooms/standing';
import {
    ChoiceRow,
    JoinedChips,
    StandingCard,
    useTopOnTurn,
} from '@/components/rooms/start-parts';
import { useLiveRoom } from '@/components/rooms/use-live-room';
import { useRoomMotion } from '@/components/rooms/use-motion';
import type { RoomMotion } from '@/components/rooms/use-motion';
import { useRoomSound } from '@/components/rooms/use-room-sound';
import { useRoomVoice } from '@/components/rooms/use-room-voice';
import { VoiceButton, VoiceSwitchRow } from '@/components/rooms/voice-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { edit as editProfile } from '@/routes/profile';
import { close, destroy, entrance, leave } from '@/routes/rooms';
import { destroy as endGame } from '@/routes/rooms/game';
import { voice as memberVoice } from '@/routes/rooms/members';
import { destroy as forget } from '@/routes/rooms/name';
import type {
    RoomGame,
    RoomMechanic,
    RoomRankedPlayer,
    RoomSetup,
    RoomState,
} from '@/rooms/contract';
import type { RoomFinishVoice, RoomSoundUrls } from '@/rooms/sound';
import { roomRequest } from '@/rooms/http';

/** A game of a closed room, with its ranking (`Rooms\Support\RoomResults`). */
export type RoomResultGame = {
    id: string;
    mechanic: RoomMechanic;
    difficulty: number;
    rounds: number;
    ranking: RoomRankedPlayer[];
};

type Can = { host: boolean; leave: boolean; delete: boolean; forget: boolean };

type Props = {
    room: { id: string; status: 'open' | 'closed' };
    /** Null for a player who left an open room: no live state for them. */
    state: RoomState | null;
    /** The label of place n at n − 1, from the server. */
    place_labels: string[];
    /** What the room's choice may be; for the host of an open room only (the choice itself is the state's `setup`). */
    catalog: GameSetupOptions | null;
    /** Where a phone joins the open room: the QR's address and the code field's. */
    join: JoinLink | null;
    /** A closed room's games, each with its ranking; null while the room is open. */
    games: RoomResultGame[] | null;
    /** This phone's own quiet cues, and the room's for a room without a screen, by name (ADR 025, ADR 028 as amended); null when it follows no open room. */
    sounds: RoomSoundUrls | null;
    /** The rivalry strip's emoji (ADR 027); null when it follows no open room. */
    rivalry: RivalryIcons | null;
    /** The taunts a player may throw, each key with its emoji and its clip in the board's voice (ADR 029 as amended, T3); null when it follows no open room. */
    taunts: TauntList | null;
    /** «Əla oynadın!» and «Əla oynadınız!», said after the final's fanfare in a room without a screen (ADR 030 as amended); null when it follows no open room. */
    finish: RoomFinishVoice | null;
    /** The board game the room was opened for (ADR 034); null: the round games. */
    board: 'checkers' | null;
    /** Its match as the viewer sees it; null outside an open board room. */
    match: RoomMatchState | null;
    can: Can;
};

/**
 * A room's page (docs/frontend.md rule 15 "Games first"; architecture §12
 * "Rooms and joining", "The race", "Games first"; ADR 026 decisions 16 and
 * 18). While the room is open and no game is live, the start screen: for
 * the host an invitation — the QR and the code — over the choice's row,
 * who has joined and «Başla»; for a player «Qoşuldunuz», the same row
 * read-only and the waiting line. While the state's `setup.played`, the
 * game just played: its final, then «Yenidən oyna» and the other games for
 * the host, «Aparıcı növbəti oyunu seçir.» for a player. During a game the
 * round, phase by phase, under the rivalry strip (ADR 027). Once closed,
 * its results.
 *
 * The page follows the state with `useLiveRoom()`; it sends no client
 * event, and draws what the latest state says: it never derives a phase,
 * a solution, a point or whether the choice was played. A host's tap
 * posts and the page waits for the state (`useRoomChoice()`). A moment
 * moves only when the page saw it begin (`useRoomMotion()`, ADR 025). Its
 * sound is the viewer's own and quiet, and in a room without a screen the
 * room's too (`useRoomSound()`, ADR 028 as amended 2026-11-19); a taunt
 * aimed at the viewer is said in the board's voice as its reaction pops in,
 * unless this device hides taunts (ADR 029 as amended 2026-11-19).
 *
 * The page has no app shell: it stands in the game frame (`RoomFrame`,
 * ADR 032), whose top bar holds ‹ to «Oyunlar», the moment's title — in a
 * round the round's, over the game — and the moment's buttons; the action
 * of the moment stands at the bottom of the viewport (`RoomActionBar
 * framed`).
 *
 * While the room's live voice is on — from its opening, until the host
 * turns it off (ADR 031 as amended 2026-11-19) — its round button stands in
 * the frame's top bar on every moment and opens the panel «Canlı səs», and
 * an agreed account is in the voice, listening, without a tap; the host's
 * «Otağın idarəsi» has its switch (`useRoomVoice()`, docs/frontend.md rule
 * 15 "Live voice", ADR 031). The voice is the room's, not a game's: the
 * button stays through every moment, and nothing of a round reads it.
 * While the state has no voice nothing of it is drawn and the library is
 * never loaded.
 */
export default function RoomsShow({
    room,
    state: initial,
    place_labels: placeLabels,
    catalog,
    join,
    games,
    sounds,
    rivalry,
    taunts,
    finish,
    board,
    match,
    can,
}: Props) {
    const { t } = useTranslation();
    const { state, clock, refresh } = useLiveRoom({ room, initial });
    const motion = useRoomMotion(state);
    // This device's «Mənə atılanları gizlət»: it hides a taunt's reaction and silences its voice.
    const [tauntsHidden, setTauntsHidden] = useTauntsHidden('phone');
    const tauntSound = useMemo(
        () => ({ voices: tauntVoices(taunts), hidden: tauntsHidden }),
        [taunts, tauntsHidden],
    );
    // In a room without a screen the phone cheers the winner too: the first name by ids, then «Əla oynadın!» (ADR 030 as amended).
    const cheer = useMemo(
        () =>
            finish === null
                ? undefined
                : {
                      nameUrl: (member: string) =>
                          memberVoice.url({ room: room.id, member }),
                      finish,
                  },
        [room.id, finish],
    );
    const sound = useRoomSound({
        speaker: 'phone',
        sounds,
        state,
        clock,
        taunts: tauntSound,
        cheer,
    });
    const voice = useRoomVoice({ roomId: room.id, state, onChanged: refresh });
    const voiceButton = <VoiceButton voice={voice} />;
    // A game opens at its top, where the rivalry strip stands (ADR 027).
    useTopOnTurn(
        state?.game?.phase === 'live' ? `live:${state.game.id}` : 'room',
    );

    if (room.status === 'closed') {
        return (
            <ClosedRoom
                room={room}
                state={state}
                games={games ?? []}
                placeLabels={placeLabels}
                can={can}
            />
        );
    }

    if (state === null || state.setup === null) {
        return (
            <RoomFrame heading={<FrameTitle title={t('rooms.start.title')} />}>
                <div className="mx-auto flex w-full max-w-xl flex-col gap-4 px-4 pt-4 pb-8 md:px-6">
                    <p className="text-muted-foreground">
                        {t('rooms.show.left')}
                    </p>
                    <Button asChild className="h-13 text-base">
                        <Link href={entrance()}>{t('rooms.join.title')}</Link>
                    </Button>
                </div>
            </RoomFrame>
        );
    }

    if (board !== null) {
        return (
            <CheckersRoom
                roomId={room.id}
                state={state}
                initial={match}
                host={can.host}
                canLeave={can.leave}
                join={join}
                refresh={refresh}
                voice={voiceButton}
                placeLabels={placeLabels}
                sounds={sounds}
                taunts={taunts}
            />
        );
    }

    const game = state.game;
    const round = state.round;

    if (game !== null && game.phase === 'live' && round !== null) {
        return (
            <RoomFrame
                heading={
                    <RoundTitle
                        game={game}
                        round={round}
                        motion={motion.round}
                    />
                }
                tools={
                    <>
                        {sounds !== null && <SoundToggle sound={sound} />}
                        {voiceButton}
                        {state.room.code !== null && (
                            <CodeChip code={state.room.code} join={join} />
                        )}
                    </>
                }
            >
                <Head title={t('rooms.start.title')} />
                <div className="mx-auto flex w-full max-w-xl flex-col gap-5 px-4 pt-4 pb-6 md:px-6">
                    <LiveRound
                        roomId={room.id}
                        state={state}
                        game={game}
                        round={round}
                        clock={clock}
                        placeLabels={placeLabels}
                        refresh={refresh}
                        onGone={() => router.reload()}
                        motion={motion}
                        sound={sound}
                        icons={rivalry}
                        taunts={taunts}
                        tauntsHidden={tauntsHidden}
                        onTauntsHiddenChange={setTauntsHidden}
                    />
                    {can.host && (
                        <div className="flex justify-center border-t pt-4">
                            <RoomConfirm
                                trigger={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-11 text-muted-foreground"
                                    >
                                        <CircleStop />
                                        {t('rooms.game.end')}
                                    </Button>
                                }
                                title={t('rooms.game.end_title')}
                                description={t('rooms.game.end_description')}
                                confirmLabel={t('rooms.game.end')}
                                destructive
                                onConfirm={() =>
                                    roomRequest(
                                        'DELETE',
                                        endGame.url(room.id),
                                    ).finally(refresh)
                                }
                            />
                        </div>
                    )}
                </div>
            </RoomFrame>
        );
    }

    const over = game !== null && state.setup.played ? game : null;

    if (can.host && catalog !== null) {
        return (
            <HostRoom
                roomId={room.id}
                state={state}
                setup={state.setup}
                over={over}
                catalog={catalog}
                join={join}
                placeLabels={placeLabels}
                motion={motion}
                refresh={refresh}
                voice={voiceButton}
            />
        );
    }

    return (
        <PlayerRoom
            roomId={room.id}
            state={state}
            setup={state.setup}
            over={over}
            join={join}
            placeLabels={placeLabels}
            motion={motion}
            canLeave={can.leave}
            voice={voiceButton}
        />
    );
}

/**
 * The host's page outside a game: the start screen, or after a game its
 * final with «Yenidən oyna» and the other games. One choice, one start and
 * one «Oyun ayarları» serve both, so a change made in the settings after a
 * game keeps them open while the page turns back to the start screen.
 */
function HostRoom({
    roomId,
    state,
    setup,
    over,
    catalog,
    join,
    placeLabels,
    motion,
    refresh,
    voice,
}: {
    roomId: string;
    state: RoomState;
    setup: RoomSetup;
    over: RoomGame | null;
    catalog: GameSetupOptions;
    join: JoinLink | null;
    placeLabels: string[];
    motion: RoomMotion;
    refresh: () => void;
    /** The live voice's button in the top bar, while the host has the voice on. */
    voice?: ReactNode;
}) {
    const { t } = useTranslation();
    const choice = useRoomChoice({ roomId, choice: setup, onChanged: refresh });
    const tauntsSwitch = useTauntsSwitch({
        roomId,
        on: state.room.taunts,
        onChanged: refresh,
    });
    const { starting, failed, start } = useRoomStart({
        roomId,
        onChanged: refresh,
    });
    const [settings, setSettings] = useState(false);
    const present = state.players.filter((player) => player.present).length;
    useTopOnTurn(over === null ? 'start' : over.id);
    const tools = (
        <RoomTools
            roomId={roomId}
            state={state}
            onChanged={refresh}
            voice={
                state.voice !== null ? (
                    <VoiceSwitchRow
                        roomId={roomId}
                        state={state}
                        onChanged={refresh}
                    />
                ) : null
            }
        />
    );

    return (
        <RoomFrame
            heading={
                <FrameTitle
                    title={t(
                        over !== null
                            ? 'rooms.start.final'
                            : 'rooms.start.invite',
                    )}
                    badge={state.room.locked ? <LockedBadge /> : null}
                />
            }
            tools={
                <>
                    {voice}
                    {tools}
                </>
            }
        >
            <div className="mx-auto flex w-full max-w-xl flex-col gap-2.5 px-4 pt-3 pb-4 md:px-6">
                {over !== null ? (
                    <>
                        <GameOver
                            state={state}
                            game={over}
                            placeLabels={placeLabels}
                            motion={motion}
                        />
                        <div className="flex justify-center pt-1">
                            <RoomConfirm
                                trigger={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-11 text-red-600 hover:text-red-600 dark:text-red-400 dark:hover:text-red-400"
                                    >
                                        <LogOut />
                                        {t('rooms.show.close')}
                                    </Button>
                                }
                                title={t('rooms.show.close_title')}
                                description={t('rooms.show.close_description')}
                                confirmLabel={t('rooms.show.close')}
                                destructive
                                form={close.form(roomId)}
                            />
                        </div>
                    </>
                ) : (
                    <>
                        {state.game !== null && (
                            <StandingCard
                                state={state}
                                placeLabels={placeLabels}
                            />
                        )}
                        {state.room.code !== null && (
                            <InviteCard
                                code={state.room.code}
                                join={join}
                                qr={state.game !== null ? 120 : 160}
                            />
                        )}
                        <ChoiceRow
                            choice={choice.shown}
                            onChange={() => setSettings(true)}
                        />
                        <section className="flex flex-col gap-2">
                            <h2 className="text-sm font-semibold">
                                {t('rooms.start.joined_count', {
                                    count: present,
                                })}
                            </h2>
                            <JoinedChips
                                roomId={roomId}
                                state={state}
                                removable
                            />
                        </section>
                    </>
                )}
            </div>

            <RoomActionBar framed>
                {(failed || (over !== null && choice.failed)) && (
                    <InputError
                        message={
                            failed
                                ? t('rooms.setup.failed')
                                : t('rooms.setup.change_failed')
                        }
                    />
                )}
                {over !== null ? (
                    <>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                className="h-13 min-w-0 flex-1 text-base"
                                disabled={starting || choice.pending !== null}
                                onClick={start}
                            >
                                {starting ? <Spinner /> : <RotateCcw />}
                                {t('rooms.start.replay')}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                className="size-13 shrink-0"
                                aria-label={t('rooms.setup.title')}
                                onClick={() => setSettings(true)}
                            >
                                <SlidersHorizontal className="size-5" />
                            </Button>
                        </div>
                        <OtherGames
                            catalog={catalog}
                            current={setup.mechanic}
                            choice={choice}
                            disabled={starting}
                        />
                    </>
                ) : (
                    <Button
                        type="button"
                        className="h-13 w-full text-base"
                        disabled={
                            present === 0 || starting || choice.pending !== null
                        }
                        onClick={start}
                    >
                        {starting ? <Spinner /> : <Play />}
                        {t('rooms.start.start', { count: present })}
                    </Button>
                )}
            </RoomActionBar>

            <GameSettings
                options={catalog}
                choice={choice}
                open={settings}
                onOpenChange={setSettings}
                taunts={tauntsSwitch}
            />
        </RoomFrame>
    );
}

/** «Başqa oyun:» and the five other games as chips in a row that scrolls sideways, each choosing that game. */
function OtherGames({
    catalog,
    current,
    choice,
    disabled,
}: {
    catalog: GameSetupOptions;
    /** The game just played: the state's choice. */
    current: RoomMechanic;
    choice: RoomChoice;
    disabled: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-1.5">
            <span className="text-xs font-medium text-muted-foreground">
                {t('rooms.start.other')}
            </span>
            <ul className="-mx-4 flex [scrollbar-width:none] gap-2 overflow-x-auto px-4 pb-0.5 md:-mx-6 md:px-6">
                {catalog.mechanics
                    .filter((game) => game.mechanic !== current)
                    .map((game) => (
                        <li key={game.mechanic} className="shrink-0">
                            <button
                                type="button"
                                disabled={disabled || choice.pending !== null}
                                onClick={() =>
                                    choice.choose(
                                        withGame(choice.shown, game),
                                        'mechanic',
                                    )
                                }
                                className="flex h-11 items-center gap-2 rounded-full border bg-card py-1 pr-3.5 pl-1.5 text-sm font-medium whitespace-nowrap outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait disabled:opacity-60"
                            >
                                <GameDrawing
                                    mechanic={game.mechanic}
                                    className="text-[32px]"
                                />
                                {t(`rooms.mechanics.${game.mechanic}.short`)}
                                {choice.pending === 'mechanic' &&
                                    choice.shown.mechanic === game.mechanic && (
                                        <Spinner />
                                    )}
                            </button>
                        </li>
                    ))}
            </ul>
        </div>
    );
}

/**
 * A player's page outside a game: «Qoşuldunuz» with the choice's row,
 * which nobody but the host changes, how many have joined, «Dostunu
 * çağır» and the waiting line; after a game its final and «Aparıcı
 * növbəti oyunu seçir.». A host's change of the choice reaches it with
 * the state, and no control here suggests otherwise.
 */
function PlayerRoom({
    roomId,
    state,
    setup,
    over,
    join,
    placeLabels,
    motion,
    canLeave,
    voice,
}: {
    roomId: string;
    state: RoomState;
    setup: RoomSetup;
    over: RoomGame | null;
    join: JoinLink | null;
    placeLabels: string[];
    motion: RoomMotion;
    canLeave: boolean;
    /** The live voice's button in the top bar, while the host has the voice on. */
    voice?: ReactNode;
}) {
    const { t } = useTranslation();
    const present = state.players.filter((player) => player.present).length;
    const me = state.players.find((player) => player.id === state.me.player_id);
    useTopOnTurn(over === null ? 'start' : over.id);
    const leaving = canLeave && (
        <Form {...leave.form(roomId)} className="flex justify-center">
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="ghost"
                    className="h-11 text-muted-foreground"
                    disabled={processing}
                >
                    <LogOut />
                    {t('rooms.show.leave')}
                </Button>
            )}
        </Form>
    );

    return (
        <RoomFrame
            heading={
                <FrameTitle
                    title={t(
                        over !== null
                            ? 'rooms.start.final'
                            : 'rooms.start.title',
                    )}
                />
            }
            tools={voice}
        >
            <div className="mx-auto flex w-full max-w-xl flex-col gap-2.5 px-4 pt-3 pb-4 md:px-6">
                {over !== null ? (
                    <>
                        <GameOver
                            state={state}
                            game={over}
                            placeLabels={placeLabels}
                            motion={motion}
                        />
                        {leaving}
                    </>
                ) : (
                    <>
                        {state.game !== null && (
                            <StandingCard
                                state={state}
                                placeLabels={placeLabels}
                            />
                        )}
                        <div className="flex flex-col items-center gap-1 py-2 text-center">
                            <span className="mb-1.5 flex size-16 items-center justify-center rounded-full bg-emerald-600/12 text-emerald-600 dark:bg-emerald-400/15 dark:text-emerald-400">
                                <Check aria-hidden className="size-8" />
                            </span>
                            <span className="text-xl font-semibold">
                                {t('rooms.start.joined')}
                            </span>
                            {me?.name != null && (
                                // The name one joined under, and «Dəyiş» into Settings → Profil
                                // (ADR 033 decision 9): a link may have skipped the join page.
                                <span className="flex max-w-full items-center gap-1">
                                    <span className="min-w-0 truncate text-sm text-muted-foreground">
                                        {t('rooms.start.joined_as', {
                                            name: me.name,
                                        })}
                                    </span>
                                    <Button
                                        asChild
                                        variant="ghost"
                                        className="h-11 shrink-0 px-3 text-muted-foreground"
                                    >
                                        <Link href={editProfile()}>
                                            {t('rooms.join.change_name')}
                                        </Link>
                                    </Button>
                                </span>
                            )}
                        </div>
                        <ChoiceRow choice={setup} />
                        <p className="text-center text-sm text-muted-foreground">
                            {t('rooms.start.players_joined', {
                                count: present,
                            })}
                        </p>
                        {state.room.code !== null && (
                            <div className="flex justify-center">
                                <CodeDialog
                                    code={state.room.code}
                                    join={join}
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="h-11"
                                        >
                                            <UserPlus />
                                            {t('rooms.start.invite_friend')}
                                        </Button>
                                    }
                                />
                            </div>
                        )}
                        {leaving}
                    </>
                )}
            </div>

            <RoomActionBar framed>
                <p className="flex min-h-11 items-center justify-center gap-2 text-center text-sm text-muted-foreground">
                    <Hourglass aria-hidden className="size-4 shrink-0" />
                    {over !== null
                        ? t('rooms.show.waiting_next')
                        : t('rooms.show.waiting')}
                </p>
            </RoomActionBar>
        </RoomFrame>
    );
}

/** A game's end: «Oyun bitdi» with what was played, and its final (the room's standing, ADR 025). */
function GameOver({
    state,
    game,
    placeLabels,
    motion,
}: {
    state: RoomState;
    game: RoomGame;
    placeLabels: string[];
    motion: RoomMotion;
}) {
    const { t } = useTranslation();

    return (
        <section
            key={game.id}
            data-motion={motion.phase ? 'on' : undefined}
            className="flex flex-col gap-3"
        >
            <div className="flex items-center gap-3 room-motion:animate-room-enter">
                <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-amber-400 text-amber-950 room-motion:animate-room-pop">
                    <Trophy aria-hidden className="size-5" />
                </span>
                <span className="flex min-w-0 flex-col">
                    <span className="font-semibold">
                        {t('rooms.game.over')}
                    </span>
                    <span className="truncate text-sm text-muted-foreground">
                        {t('rooms.setup.summary', {
                            game: t(`rooms.mechanics.${game.mechanic}.name`),
                            difficulty: t(
                                `rooms.difficulty.${game.difficulty}`,
                            ),
                            count: game.rounds,
                        })}
                    </span>
                </span>
            </div>
            <FinalStanding
                ranking={state.ranking}
                players={state.players}
                me={state.me.player_id}
                placeLabels={placeLabels}
                animate={motion.phase}
                forRoom={carriesRoom(state)}
            />
        </section>
    );
}

/**
 * A closed room: its results (docs/frontend.md rule 15, "A closed room") —
 * the room's standing, each game with its ranking, and the ways out:
 * «Adımı sil» for a player, «Otağı sil» for the host. Names are read from
 * the accounts as they are now; a name taken out reads «Oyunçu».
 */
function ClosedRoom({
    room,
    state,
    games,
    placeLabels,
    can,
}: {
    room: Props['room'];
    state: RoomState | null;
    games: RoomResultGame[];
    placeLabels: string[];
    can: Can;
}) {
    const { t } = useTranslation();

    return (
        <RoomFrame
            back="past"
            heading={
                <FrameTitle
                    title={t('rooms.show.results')}
                    badge={
                        <Badge variant="secondary" className="h-6 shrink-0">
                            {t('rooms.show.closed_badge')}
                        </Badge>
                    }
                />
            }
        >
            <div className="mx-auto flex w-full max-w-xl flex-col gap-6 px-4 pt-4 pb-10 md:px-6">
                <p className="text-sm text-muted-foreground">
                    {t('rooms.show.closed')}
                </p>

                {state !== null && (
                    <section className="flex flex-col gap-2">
                        <h2 className="font-semibold">
                            {t('rooms.game.standing')}
                        </h2>
                        {games.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('rooms.show.no_games')}
                            </p>
                        ) : (
                            <Standing
                                ranking={state.ranking}
                                players={state.players}
                                me={state.me.player_id}
                                placeLabels={placeLabels}
                            />
                        )}
                    </section>
                )}

                {state !== null && games.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="font-semibold">
                            {t('rooms.show.games_count', {
                                count: games.length,
                            })}
                        </h2>
                        {games.map((played, position) => (
                            <GameResult
                                key={played.id}
                                game={played}
                                state={state}
                                placeLabels={placeLabels}
                                defaultOpen={position === 0}
                            />
                        ))}
                    </section>
                )}

                {(can.forget || can.delete) && (
                    <section className="flex flex-col gap-5 border-t pt-6">
                        {can.forget && (
                            <div className="flex flex-col gap-2">
                                <p className="text-sm text-muted-foreground">
                                    {t('rooms.show.forget_line')}
                                </p>
                                <RoomConfirm
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="h-11"
                                        >
                                            <UserMinus />
                                            {t('rooms.show.forget')}
                                        </Button>
                                    }
                                    title={t('rooms.show.forget_title')}
                                    description={t('rooms.show.forget_line')}
                                    confirmLabel={t('rooms.show.forget')}
                                    destructive
                                    form={forget.form(room.id)}
                                />
                            </div>
                        )}
                        {can.delete && (
                            <div className="flex flex-col gap-2">
                                <p className="text-sm text-muted-foreground">
                                    {t('rooms.show.delete_line')}
                                </p>
                                <RoomConfirm
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            className="h-11"
                                        >
                                            <Trash2 />
                                            {t('rooms.show.delete')}
                                        </Button>
                                    }
                                    title={t('rooms.show.delete_title')}
                                    description={t('rooms.show.delete_line')}
                                    confirmLabel={t('rooms.show.delete')}
                                    destructive
                                    form={destroy.form(room.id)}
                                />
                            </div>
                        )}
                    </section>
                )}
            </div>
        </RoomFrame>
    );
}

/** One game of a closed room: its name, difficulty and rounds over its ranking, which opens and closes. */
function GameResult({
    game,
    state,
    placeLabels,
    defaultOpen,
}: {
    game: RoomResultGame;
    state: RoomState;
    placeLabels: string[];
    defaultOpen: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(defaultOpen);
    const panel = `game-${game.id}`;

    return (
        <div className="flex flex-col gap-2">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panel}
                onClick={() => setOpen(!open)}
                className="flex min-h-12 items-center gap-3 rounded-xl border bg-card px-3 py-2 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                <GameDrawing mechanic={game.mechanic} className="text-[36px]" />
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="truncate font-medium">
                        {t(`rooms.mechanics.${game.mechanic}.name`)}
                    </span>
                    <span className="text-sm text-muted-foreground">
                        {t(`rooms.difficulty.${game.difficulty}`)}
                        {' · '}
                        {t('rooms.show.rounds', { count: game.rounds })}
                    </span>
                </span>
                <ChevronDown
                    aria-hidden
                    className={cn(
                        'size-5 shrink-0 text-muted-foreground transition-transform motion-reduce:transition-none',
                        open && 'rotate-180',
                    )}
                />
            </button>
            <div id={panel} hidden={!open}>
                <Standing
                    ranking={game.ranking}
                    players={state.players}
                    me={state.me.player_id}
                    placeLabels={placeLabels}
                />
            </div>
        </div>
    );
}
