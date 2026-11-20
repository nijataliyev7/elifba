import { ScreenCheckers } from '@/components/rooms/checkers/screen-checkers';
import type { RoomMatchState } from '@/components/rooms/checkers/match';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Headphones,
    Hourglass,
    Maximize,
    MicOff,
    Minimize,
    Pencil,
    Play,
    RotateCcw,
    Trophy,
} from 'lucide-react';
import { useCallback, useMemo, useState, useSyncExternalStore } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { withGame } from '@/components/rooms/catalog';
import type { GameSetupOptions } from '@/components/rooms/catalog';
import { FinalStanding } from '@/components/rooms/final-standing';
import { GameDrawing } from '@/components/rooms/game-drawing';
import {
    GameSettings,
    useRoomChoice,
    useRoomStart,
} from '@/components/rooms/game-settings';
import type { RoomChoice } from '@/components/rooms/game-settings';
import { Countdown, Counted, RoundHead } from '@/components/rooms/live-round';
import { MECHANIC_VIEWS } from '@/components/rooms/mechanics/views';
import { REVEAL_MS } from '@/components/rooms/motion';
import { RaceLanes } from '@/components/rooms/race-lanes';
import { RivalryStrip, useRivalry } from '@/components/rooms/rivalry-strip';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import {
    ScreenTauntReaction,
    ScreenTauntsToggle,
    tauntVoices,
    useTauntsHidden,
} from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import { RoomQr, spacedCode, typedAddress } from '@/components/rooms/room-code';
import { ScreenFit } from '@/components/rooms/screen-fit';
import type { JoinLink } from '@/components/rooms/room-code';
import { SoundToggle } from '@/components/rooms/sound-toggle';
import { useTauntsSwitch } from '@/components/rooms/taunts-switch';
import { Standing } from '@/components/rooms/standing';
import { StandingCard, useChoiceLine } from '@/components/rooms/start-parts';
import { useLiveRoom } from '@/components/rooms/use-live-room';
import {
    usePreviousReveal,
    useRoomMotion,
} from '@/components/rooms/use-motion';
import type { RoomMotion } from '@/components/rooms/use-motion';
import { useRoomSound } from '@/components/rooms/use-room-sound';
import { useServerNow } from '@/components/rooms/use-server-now';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { show } from '@/routes/rooms';
import { voice } from '@/routes/rooms/members';
import type { ServerClock } from '@/rooms/clock';
import type {
    RoomGame,
    RoomPlayer,
    RoomRound,
    RoomSetup,
    RoomState,
} from '@/rooms/contract';
import type { RoomFinishVoice, RoomSoundUrls } from '@/rooms/sound';
import { screenVoiceView } from '@/rooms/voice-view';
import type { ScreenVoiceView } from '@/rooms/voice-view';

type Props = {
    room: { id: string; status: 'open' | 'closed' };
    state: RoomState;
    /** The label of place n at n − 1, from the server. */
    place_labels: string[];
    /** Where a phone joins: the QR's address and the code field's. */
    join: JoinLink | null;
    /** What the room's choice may be, as the host's phone gets it: the screen sends the host's choice and start with it. */
    catalog: GameSetupOptions;
    /** The room's moments it plays as the room's speaker, by name (ADR 025). */
    sounds: RoomSoundUrls;
    /** The rivalry strip's emoji (ADR 027). */
    rivalry: RivalryIcons;
    /** The taunts' list, each key with its emoji and its clip in the board's voice (ADR 029 as amended, T3). */
    taunts: TauntList;
    /** «Əla oynadın!» and «Əla oynadınız!», said after the final's fanfare (ADR 030). */
    finish: RoomFinishVoice;
    /** How often the shown screen reads the state at least, so phones know the room has its screen (ADR 028 as amended). */
    beat_ms: number;
    /** The board game the room was opened for (ADR 034); null: the round games. */
    board: 'checkers' | null;
    /** Its match as the screen sees it, every taunt with its target. */
    match: RoomMatchState | null;
};

/**
 * The big screen (docs/frontend.md rule 15 "Games first", "The screen";
 * architecture §12 "The big screen", "Games first"; ADR 026 decision 17):
 * the host's own session on a laptop or a television's browser, without
 * the app's shell. Before a game the invitation as a poster — the QR, the
 * code at its largest, the address, who has joined — over one strip with
 * the room's choice, «Dəyiş» and «Başla»; in a game what every player sees
 * of the round — the countdown, the material while it is studied, the
 * question with the count of answers — then the reveal with the ranking
 * and each player's result, under the rivalry strip of the first two
 * (ADR 027), the ranking as race lanes with three players or more; a
 * taunt thrown now in the middle of the screen for 3 s, «:from → :to» and
 * the phrase, said in the board's voice, which its top bar's «Atmacalar»
 * hides and silences on this screen (ADR 029 as amended 2026-11-19,
 * decisions 42 and 47); after a game its final beside «Yenidən oyna»
 * and the five other games. Before and after a game it sends the host's
 * own requests for the choice and the start (`useRoomChoice()`,
 * `useRoomStart()`), which the server lets through for the host; it holds
 * no lock, removal, closing or ending, and in a game it writes nothing.
 *
 * It is the room's show (ADR 025): every moment it saw begin moves, larger
 * than on a phone, and a game's end is the final, with its confetti. A
 * moment it was opened on stands still. It is also the room's speaker:
 * once its «Səsi aç» is pressed (browsers allow no sound before a
 * gesture), it plays the moments everybody shares (`useRoomSound()`) —
 * and the same whatever anyone answered, since it has no viewer — and,
 * after the final's fanfare, cheers the first place as a home board ends a
 * game: the first name from the name list's clip, then «Əla oynadın!»
 * (ADR 030).
 *
 * It joins no live voice (docs/frontend.md rule 15 "Live voice", ADR 031
 * decision 25): it asks for no ticket, plays and captures nothing, and
 * imports no voice connection. From the state alone (`screenVoiceView()`)
 * it shows «Canlı səs açıqdır» in its top bar, a waiting or muted player's
 * mark on the poster's chips, and that the voice is on the phones.
 */
export default function RoomsScreen({
    room,
    state: initial,
    place_labels: placeLabels,
    join,
    catalog,
    sounds,
    rivalry,
    taunts,
    finish,
    beat_ms: beatMs,
    board,
    match,
}: Props) {
    const { t } = useTranslation();
    // A closed room has no screen: its results are on the room's page.
    // The screen answers nothing: it waits through every question as one who answered.
    const { state, clock, refresh } = useLiveRoom({
        room,
        initial,
        onClosed: () => router.visit(show(room.id)),
        answers: false,
        beatMs,
    });
    const motion = useRoomMotion(state);
    const [tauntsHidden, setTauntsHidden] = useTauntsHidden('screen');
    // After the final's fanfare the screen cheers the winner: the first name, fetched by ids, and «Əla oynadın!» (ADR 030).
    const cheer = useMemo(
        () => ({
            nameUrl: (member: string) => voice.url({ room: room.id, member }),
            finish,
        }),
        [room.id, finish],
    );
    // The screen says each taunt it draws, unless its «Atmacalar» hides them (ADR 029 decision 47).
    const tauntSound = useMemo(
        () => ({ voices: tauntVoices(taunts), hidden: tauntsHidden }),
        [taunts, tauntsHidden],
    );
    const sound = useRoomSound({
        speaker: 'screen',
        sounds,
        state,
        clock,
        cheer,
        taunts: tauntSound,
    });
    const game = state?.game ?? null;
    const round = state?.round ?? null;
    const playing = game !== null && game.phase === 'live' && round !== null;
    // The live voice's facts from the state alone (the cheer's `voice` above is a route, another thing).
    const liveVoice = state === null ? null : screenVoiceView(state);

    return (
        <div
            className={cn(
                'flex flex-col bg-background text-foreground',
                // A round fills the screen exactly: its parts fit it (`ScreenFit`).
                playing ? 'h-dvh' : 'min-h-dvh',
            )}
        >
            <Head title={t('rooms.screen.title')} />

            <header className="flex items-center gap-[1.5vw] border-b px-[3vw] py-[1.4vh]">
                <span className="flex size-[max(2.5rem,5vh)] shrink-0 items-center justify-center rounded-[22%] bg-green-700 text-white">
                    <AppLogoIcon className="size-[66%] fill-current" />
                </span>
                <span className="text-[max(1rem,2.6vh)] font-semibold">
                    {t('rooms.screen.title')}
                </span>
                {state?.room.code != null && playing && (
                    <span className="rounded-full border px-[1.2vh] py-[0.4vh] text-[max(0.875rem,2.2vh)] font-semibold tabular-nums">
                        {t('rooms.screen.code_short', {
                            code: spacedCode(state.room.code),
                        })}
                    </span>
                )}
                {liveVoice !== null && (
                    <span className="inline-flex items-center gap-[0.9vh] rounded-full bg-(--voice-tint) px-[1.6vh] py-[0.5vh] text-[max(0.875rem,2.2vh)] font-semibold text-(--voice-text)">
                        <Headphones
                            aria-hidden
                            className="size-[max(1rem,2.4vh)]"
                        />
                        {t('rooms.voice.on')}
                    </span>
                )}
                <span className="flex-1" />
                {state?.room.status === 'open' && state.room.taunts && (
                    <ScreenTauntsToggle
                        hidden={tauntsHidden}
                        onChange={setTauntsHidden}
                    />
                )}
                {board === null && <SoundToggle sound={sound} big />}
                <FullscreenToggle />
                <Button asChild variant="ghost" className="h-11">
                    <Link href={show(room.id)}>
                        <ArrowLeft />
                        {t('rooms.screen.back')}
                    </Link>
                </Button>
            </header>

            {state === null ||
            room.status === 'closed' ||
            state.setup === null ? (
                <main className="flex flex-1 flex-col px-[4vw] py-[3vh]">
                    <p className="text-[3vh] text-muted-foreground">
                        {t('rooms.show.closed')}
                    </p>
                </main>
            ) : board !== null ? (
                <ScreenCheckers
                    roomId={room.id}
                    state={state}
                    initial={match}
                    join={join}
                    sounds={sounds}
                    taunts={taunts}
                    tauntsHidden={tauntsHidden}
                    placeLabels={placeLabels}
                />
            ) : playing ? (
                <main className="flex min-h-0 flex-1 flex-col px-[4vw] py-[3vh]">
                    <ScreenRound
                        state={state}
                        game={game}
                        round={round}
                        clock={clock}
                        placeLabels={placeLabels}
                        motion={motion}
                        icons={rivalry}
                        taunts={taunts}
                        tauntsHidden={tauntsHidden}
                    />
                </main>
            ) : (
                <ScreenHost
                    roomId={room.id}
                    voice={liveVoice}
                    state={state}
                    setup={state.setup}
                    over={game !== null && state.setup.played ? game : null}
                    join={join}
                    catalog={catalog}
                    placeLabels={placeLabels}
                    motion={motion}
                    refresh={refresh}
                />
            )}
        </div>
    );
}

/**
 * The screen outside a game: the poster before one, the final after one.
 * One choice, one start and one «Oyun ayarları» serve both, as on the
 * host's phone.
 */
function ScreenHost({
    roomId,
    voice,
    state,
    setup,
    over,
    join,
    catalog,
    placeLabels,
    motion,
    refresh,
}: {
    roomId: string;
    /** The state's voice facts (`screenVoiceView()`); null while nothing of voice is drawn. */
    voice: ScreenVoiceView | null;
    state: RoomState;
    setup: RoomSetup;
    over: RoomGame | null;
    join: JoinLink | null;
    catalog: GameSetupOptions;
    placeLabels: string[];
    motion: RoomMotion;
    refresh: () => void;
}) {
    const choice = useRoomChoice({ roomId, choice: setup, onChanged: refresh });
    const tauntsSwitch = useTauntsSwitch({
        roomId,
        on: state.room.taunts,
        onChanged: refresh,
    });
    const start = useRoomStart({ roomId, onChanged: refresh });
    const [settings, setSettings] = useState(false);

    return (
        <>
            {over !== null ? (
                <ScreenOver
                    key={over.id}
                    state={state}
                    game={over}
                    current={setup.mechanic}
                    join={join}
                    catalog={catalog}
                    choice={choice}
                    start={start}
                    placeLabels={placeLabels}
                    motion={motion}
                />
            ) : (
                <ScreenStart
                    state={state}
                    voice={voice}
                    join={join}
                    choice={choice}
                    start={start}
                    placeLabels={placeLabels}
                    onChange={() => setSettings(true)}
                />
            )}
            <GameSettings
                options={catalog}
                choice={choice}
                open={settings}
                onOpenChange={setSettings}
                big
                taunts={tauntsSwitch}
            />
        </>
    );
}

type Start = ReturnType<typeof useRoomStart>;

/**
 * The present players as chips; one who joins after the screen first drew
 * pops in. While the live voice is on, a player who waits to be let in or
 * whom the host muted says so on the chip, an icon with its word (C4).
 */
function PlayerChips({
    players,
    voice = null,
}: {
    players: RoomPlayer[];
    voice?: ScreenVoiceView | null;
}) {
    const { t } = useTranslation();
    const present = players.filter((player) => player.present);
    const [first] = useState(() => new Set(present.map((player) => player.id)));

    if (present.length === 0) {
        return (
            <p className="text-[2.4vh] text-muted-foreground">
                {t('rooms.start.nobody')}
            </p>
        );
    }

    return (
        <ul className="flex flex-wrap gap-[1.2vh]">
            {present.map((player) => (
                <li
                    key={player.id}
                    className={cn(
                        'max-w-[24vw] truncate rounded-full border bg-card px-[2vh] py-[0.9vh] text-[max(1rem,2.6vh)]',
                        !first.has(player.id) && 'motion-safe:animate-room-pop',
                    )}
                >
                    {player.name ?? t('rooms.show.nameless')}
                    {player.host && (
                        <span className="ml-[1vh] text-[0.8em] text-muted-foreground">
                            {t('rooms.show.host')}
                        </span>
                    )}
                    {voice?.marks[player.id] !== undefined && (
                        <VoiceMark mark={voice.marks[player.id]} />
                    )}
                </li>
            ))}
        </ul>
    );
}

/** «icazə gözləyir» with `Hourglass`, or «aparıcı susdurub» with `MicOff`, on a player's chip. */
function VoiceMark({ mark }: { mark: 'waiting' | 'muted' }) {
    const { t } = useTranslation();
    const Icon = mark === 'waiting' ? Hourglass : MicOff;

    return (
        <span className="ml-[1vh] inline-flex items-center gap-[0.4vh] align-middle text-[0.75em] font-semibold text-muted-foreground">
            <Icon aria-hidden className="size-[1em]" />
            {t(`rooms.voice.state.${mark}`)}
        </span>
    );
}

/**
 * Before a game: the invitation as a poster — the QR beside «Oyun kodu»,
 * the code at its largest, the address, a thin rule and who has joined —
 * over one strip with the choice's drawing, name and line, «Dəyiş» and
 * «Başla», the largest control on the page. After a first game the room's
 * standing so far stands quietly above the code.
 */
function ScreenStart({
    state,
    voice,
    join,
    choice,
    start,
    placeLabels,
    onChange,
}: {
    state: RoomState;
    voice: ScreenVoiceView | null;
    join: JoinLink | null;
    choice: RoomChoice;
    start: Start;
    placeLabels: string[];
    onChange: () => void;
}) {
    const { t } = useTranslation();
    const line = useChoiceLine();
    const present = state.players.filter((player) => player.present).length;
    const shown = choice.shown;

    return (
        <main className="flex flex-1 flex-col">
            <div className="flex flex-1 items-center justify-center gap-[5vw] px-[4vw] py-[4vh]">
                {join !== null && (
                    <RoomQr
                        url={join.url}
                        size={512}
                        fluid
                        className="w-[min(52vh,30vw)] p-[1.4vh]"
                    />
                )}
                <div className="flex min-w-0 flex-col gap-[1.6vh]">
                    {state.game !== null && (
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
                    {join !== null && (
                        <span className="max-w-[44vw] text-[max(0.9375rem,2.6vh)] text-balance text-muted-foreground">
                            {t('rooms.screen.join', {
                                address: typedAddress(join.entry_url),
                            })}
                        </span>
                    )}
                    <hr className="my-[0.6vh]" />
                    <h2 className="text-[max(1rem,2.6vh)] font-semibold">
                        {t('rooms.start.joined_count', { count: present })}
                    </h2>
                    <PlayerChips players={state.players} voice={voice} />
                    {voice !== null && (
                        <p className="flex max-w-[44vw] items-center gap-[1vh] text-[max(0.875rem,2.1vh)] text-muted-foreground">
                            <Headphones
                                aria-hidden
                                className="size-[1.1em] shrink-0"
                            />
                            {t('rooms.voice.screen')}
                        </p>
                    )}
                </div>
            </div>

            <section className="flex min-h-[16vh] items-center gap-[2vw] border-t bg-muted px-[4vw] py-[2vh]">
                <GameDrawing
                    key={shown.mechanic}
                    mechanic={shown.mechanic}
                    className="bg-background text-[max(3rem,9vh)]"
                />
                <span className="flex min-w-0 flex-1 flex-col gap-[0.4vh]">
                    <span className="truncate text-[max(1.125rem,3.4vh)] leading-tight font-bold">
                        {t(`rooms.mechanics.${shown.mechanic}.name`)}
                    </span>
                    <span className="truncate text-[max(0.9375rem,2.4vh)] text-muted-foreground">
                        {line(shown)}
                    </span>
                </span>
                {(start.failed || choice.failed) && (
                    <InputError
                        className="max-w-[20vw] text-[max(0.875rem,1.8vh)]"
                        message={
                            start.failed
                                ? t('rooms.setup.failed')
                                : t('rooms.setup.change_failed')
                        }
                    />
                )}
                <Button
                    type="button"
                    variant="outline"
                    className="h-[max(2.75rem,7vh)] px-[2.4vh] text-[max(1rem,2.4vh)]"
                    disabled={start.starting}
                    onClick={onChange}
                >
                    <Pencil className="size-[max(1rem,2.4vh)]" />
                    {t('rooms.start.change')}
                </Button>
                <Button
                    type="button"
                    className="h-[max(3.25rem,9vh)] px-[4vh] text-[max(1.125rem,3.2vh)]"
                    disabled={
                        present === 0 ||
                        start.starting ||
                        choice.pending !== null
                    }
                    onClick={start.start}
                >
                    {start.starting ? (
                        <Spinner className="size-[max(1.25rem,3vh)]" />
                    ) : (
                        <Play className="size-[max(1.25rem,3vh)]" />
                    )}
                    {t('rooms.start.start', { count: present })}
                </Button>
            </section>
        </main>
    );
}

/**
 * After a game: its end and its final (the room's standing, ADR 025) at
 * the left, and a right column with «Yenidən oyna», «Başqa oyun:» over the
 * five other games and the code for whoever comes next.
 */
function ScreenOver({
    state,
    game,
    current,
    join,
    catalog,
    choice,
    start,
    placeLabels,
    motion,
}: {
    state: RoomState;
    game: RoomGame;
    current: RoomSetup['mechanic'];
    join: JoinLink | null;
    catalog: GameSetupOptions;
    choice: RoomChoice;
    start: Start;
    placeLabels: string[];
    motion: RoomMotion;
}) {
    const { t } = useTranslation();
    const busy = start.starting || choice.pending !== null;

    return (
        <main
            data-motion={motion.phase ? 'on' : undefined}
            className="grid flex-1 grid-cols-[minmax(0,1fr)_27vw] gap-[4vw] px-[4vw] py-[3vh]"
        >
            <section className="flex min-w-0 flex-col gap-[2.4vh]">
                <div className="flex items-center gap-[2vh] room-motion:animate-room-enter">
                    <span className="flex size-[8vh] items-center justify-center rounded-full bg-amber-400 text-amber-950 room-motion:animate-room-pop">
                        <Trophy aria-hidden className="size-[4.4vh]" />
                    </span>
                    <span className="flex flex-col">
                        <span className="text-[4.4vh] leading-tight font-bold">
                            {t('rooms.game.over')}
                        </span>
                        <span className="text-[2.4vh] text-muted-foreground">
                            {t('rooms.setup.summary', {
                                game: t(
                                    `rooms.mechanics.${game.mechanic}.name`,
                                ),
                                difficulty: t(
                                    `rooms.difficulty.${game.difficulty}`,
                                ),
                                count: game.rounds,
                            })}
                        </span>
                    </span>
                </div>
                <h2 className="text-[2.8vh] font-semibold">
                    {t('rooms.game.standing')}
                </h2>
                <FinalStanding
                    ranking={state.ranking}
                    players={state.players}
                    me={null}
                    placeLabels={placeLabels}
                    animate={motion.phase}
                    big
                />
            </section>

            <aside className="flex min-w-0 flex-col gap-[1.6vh] self-start">
                <Button
                    type="button"
                    className="h-[max(3.25rem,9vh)] text-[max(1.125rem,3vh)]"
                    disabled={busy}
                    onClick={start.start}
                >
                    {start.starting ? (
                        <Spinner className="size-[max(1.25rem,3vh)]" />
                    ) : (
                        <RotateCcw className="size-[max(1.25rem,3vh)]" />
                    )}
                    {t('rooms.start.replay')}
                </Button>
                {(start.failed || choice.failed) && (
                    <InputError
                        className="text-[max(0.875rem,1.8vh)]"
                        message={
                            start.failed
                                ? t('rooms.setup.failed')
                                : t('rooms.setup.change_failed')
                        }
                    />
                )}
                <span className="mt-[1vh] text-[max(0.9375rem,2.2vh)] font-medium text-muted-foreground">
                    {t('rooms.start.other')}
                </span>
                <ul className="flex flex-col gap-[1vh]">
                    {catalog.mechanics
                        .filter((one) => one.mechanic !== current)
                        .map((one) => (
                            <li key={one.mechanic}>
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() =>
                                        choice.choose(
                                            withGame(choice.shown, one),
                                            'mechanic',
                                        )
                                    }
                                    className="flex min-h-[max(2.75rem,7vh)] w-full items-center gap-[1.4vh] rounded-xl border bg-card px-[1.4vh] py-[0.8vh] text-left text-[max(0.9375rem,2.2vh)] font-medium outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait disabled:opacity-60"
                                >
                                    <GameDrawing
                                        mechanic={one.mechanic}
                                        className="text-[max(2rem,5vh)]"
                                    />
                                    <span className="min-w-0 flex-1 truncate">
                                        {t(
                                            `rooms.mechanics.${one.mechanic}.name`,
                                        )}
                                    </span>
                                    {choice.pending === 'mechanic' &&
                                        choice.shown.mechanic ===
                                            one.mechanic && <Spinner />}
                                </button>
                            </li>
                        ))}
                </ul>
                {state.room.code !== null && (
                    <p className="mt-[1vh] text-[max(0.875rem,2vh)] text-muted-foreground tabular-nums">
                        {t('rooms.screen.code_short', {
                            code: spacedCode(state.room.code),
                        })}
                        {join !== null && ` · ${typedAddress(join.entry_url)}`}
                    </p>
                )}
            </aside>
        </main>
    );
}

/**
 * The current round as every player sees it, without anyone's own answer:
 * the rivalry strip of the first two (ADR 027), the seconds and the bar of
 * the estimated server clock (for show only), the material only while the
 * state carries it and the clock is before the question, the question with
 * the count of answers, then the reveal — the solution beside the plain
 * ranking with two players, beside the race lanes with three or more.
 */
function ScreenRound({
    state,
    game,
    round,
    clock,
    placeLabels,
    motion,
    icons,
    taunts,
    tauntsHidden,
}: {
    state: RoomState;
    game: RoomGame;
    round: RoomRound;
    clock: ServerClock;
    placeLabels: string[];
    motion: RoomMotion;
    icons: RivalryIcons;
    taunts: TauntList;
    /** This screen hides taunts (its «Atmacalar»). */
    tauntsHidden: boolean;
}) {
    const { t } = useTranslation();
    const now = useServerNow(clock, true);
    const view = MECHANIC_VIEWS[game.mechanic];
    const previous = usePreviousReveal(game, round, state.ranking);
    const rivalry = useRivalry(state, game, round, null);
    const race = rivalry.pair?.mode === 'race';
    // A round's study and question share one size (`ScreenFit`): the question's
    // grid takes the place the study's had.
    const shared = round.phase === 'study' || round.phase === 'answer';
    const [fitted, setFitted] = useState<{ round: number; zoom: number }>({
        round: -1,
        zoom: 1,
    });
    const cap = shared && fitted.round === round.index ? fitted.zoom : 1;
    const index = round.index;
    const remember = useCallback(
        (zoom: number) =>
            setFitted((last) =>
                last.round === index && last.zoom <= zoom
                    ? last
                    : { round: index, zoom },
            ),
        [index],
    );
    const fit = shared ? remember : undefined;
    const ranking = {
        delay: REVEAL_MS.ranking,
        motion: motion.phase ? { previous, delay: REVEAL_MS.ranking } : null,
    };

    return (
        <section
            data-phase={round.phase}
            data-round={round.index}
            className={cn(
                'flex min-h-0 flex-1 flex-col',
                rivalry.pair === null ? 'gap-[3vh]' : 'gap-[2vh]',
            )}
        >
            {rivalry.pair !== null && (
                <RivalryStrip
                    key={`strip:${round.index}:${round.phase === 'reveal' ? 'reveal' : 'open'}`}
                    pair={rivalry.pair}
                    previous={rivalry.previous}
                    overtake={rivalry.overtake}
                    ranking={state.ranking}
                    results={round.reveal?.results ?? null}
                    spent={round.reveal?.spent ?? []}
                    players={state.players}
                    placeLabels={placeLabels}
                    icons={icons}
                    moves={motion.strip}
                    big
                />
            )}

            <ScreenTauntReaction
                shown={state.taunts?.shown}
                players={state.players}
                taunts={taunts}
                now={now}
                hidden={tauntsHidden}
            />

            <RoundHead
                game={game}
                round={round}
                now={now}
                motion={motion.round}
                big
            />

            <div
                key={`${round.index}:${round.phase}`}
                data-motion={motion.phase ? 'on' : undefined}
                className="flex min-h-0 flex-1 flex-col"
            >
                {round.phase === 'countdown' && (
                    <ScreenFit className="flex-1">
                        <Countdown game={game} round={round} now={now} big />
                    </ScreenFit>
                )}

                {round.phase === 'study' && (
                    <ScreenFit className="flex-1" cap={cap} onFit={fit}>
                        <div className="flex flex-1 flex-col items-center justify-center gap-[4vh] pb-[8vh]">
                            <p className="text-[4vh] font-semibold room-motion:animate-room-enter">
                                {t(`rooms.mechanics.${game.mechanic}.study`)}
                            </p>
                            {round.study !== null && (
                                <view.Study
                                    study={round.study}
                                    covered={
                                        now === null ||
                                        now >= round.answer_from_ms
                                    }
                                    big
                                />
                            )}
                        </div>
                    </ScreenFit>
                )}

                {round.phase === 'answer' && round.ask !== null && (
                    <div className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_auto] items-center gap-[4vw]">
                        <ScreenFit
                            className="h-full justify-center"
                            cap={cap}
                            onFit={fit}
                        >
                            <div className="flex flex-1 flex-col items-center justify-center gap-[4vh]">
                                <p className="text-center text-[4vh] font-semibold room-motion:animate-room-enter">
                                    {t(`rooms.mechanics.${game.mechanic}.ask`)}
                                </p>
                                <view.Ask
                                    ask={round.ask}
                                    picked={[]}
                                    disabled
                                    onPick={() => {}}
                                    big
                                />
                            </div>
                        </ScreenFit>
                        <AnsweredCount
                            count={round.answered_count}
                            total={round.present_count}
                        />
                    </div>
                )}

                {round.phase === 'reveal' &&
                    round.ask !== null &&
                    round.reveal !== null && (
                        <div
                            className={cn(
                                'grid min-h-0 flex-1 gap-[4vw]',
                                race
                                    ? 'grid-cols-[minmax(0,32fr)_minmax(0,68fr)]'
                                    : 'grid-cols-[minmax(0,5fr)_minmax(0,6fr)]',
                            )}
                        >
                            <div
                                className="flex min-h-0 flex-col room-motion:animate-room-enter"
                                style={{
                                    animationDelay: `${REVEAL_MS.solution}ms`,
                                }}
                            >
                                <ScreenFit className="flex-1">
                                    <view.Solution
                                        ask={round.ask}
                                        solution={round.reveal.solution}
                                        mine={null}
                                        word={round.reveal.word}
                                        big
                                    />
                                </ScreenFit>
                            </div>
                            <div
                                className="flex min-h-0 min-w-0 flex-col room-motion:animate-room-enter"
                                style={{
                                    animationDelay: `${ranking.delay}ms`,
                                }}
                            >
                                <ScreenFit className="flex-1">
                                    {race ? (
                                        <RaceLanes
                                            ranking={state.ranking}
                                            players={state.players}
                                            placeLabels={placeLabels}
                                            results={round.reveal.results}
                                            spent={round.reveal.spent}
                                            pair={rivalry.pair}
                                            icons={icons}
                                            motion={ranking.motion}
                                        />
                                    ) : (
                                        <section className="flex min-w-0 flex-col gap-[1.6vh]">
                                            <h2 className="text-[2.8vh] font-semibold">
                                                {t('rooms.game.ranking')}
                                            </h2>
                                            <Standing
                                                ranking={state.ranking}
                                                players={state.players}
                                                me={null}
                                                placeLabels={placeLabels}
                                                results={round.reveal.results}
                                                spent={round.reveal.spent}
                                                motion={ranking.motion}
                                                big
                                            />
                                        </section>
                                    )}
                                </ScreenFit>
                            </div>
                        </div>
                    )}
            </div>
        </section>
    );
}

/**
 * The count of answers in large figures, with a bar: it comes in after the
 * question, its figure pops at each new answer, and its card is outlined
 * once everyone present has answered.
 */
function AnsweredCount({ count, total }: { count: number; total: number }) {
    const { t } = useTranslation();
    const all = total > 0 && count >= total;

    return (
        <div
            className={cn(
                'flex w-[min(36vh,26vw)] flex-col items-center gap-[1.6vh] rounded-2xl border bg-card p-[3vh] transition-colors motion-reduce:transition-none room-motion:animate-room-enter',
                all && 'border-primary',
            )}
            style={{ animationDelay: '200ms' }}
        >
            <span className="text-[2.6vh] text-muted-foreground">
                {t('rooms.screen.answered')}
            </span>
            <span className="text-[8vh] leading-none font-bold whitespace-nowrap tabular-nums">
                <Counted value={count}>
                    {t('rooms.screen.count', { count, total })}
                </Counted>
            </span>
            <div
                aria-hidden
                className="h-[1.2vh] w-full overflow-hidden rounded-full bg-muted"
            >
                <div
                    className="h-full origin-left rounded-full bg-primary transition-transform duration-300 motion-reduce:transition-none"
                    style={{
                        transform: `scaleX(${total === 0 ? 0 : count / total})`,
                    }}
                />
            </div>
        </div>
    );
}

const noSubscription = () => () => {};

function subscribeFullscreen(change: () => void): () => void {
    document.addEventListener('fullscreenchange', change);

    return () => document.removeEventListener('fullscreenchange', change);
}

/** «Tam ekran», where the browser has it (never on an iPhone). */
function FullscreenToggle() {
    const { t } = useTranslation();
    const enabled = useSyncExternalStore(
        noSubscription,
        () => document.fullscreenEnabled,
        () => false,
    );
    const on = useSyncExternalStore(
        subscribeFullscreen,
        () => document.fullscreenElement !== null,
        () => false,
    );

    if (!enabled) {
        return null;
    }

    return (
        <Button
            type="button"
            variant="outline"
            className={cn('h-11')}
            aria-pressed={on}
            onClick={() => {
                void (
                    on
                        ? document.exitFullscreen()
                        : document.documentElement.requestFullscreen()
                ).catch(() => null);
            }}
        >
            {on ? <Minimize /> : <Maximize />}
            {t('rooms.screen.fullscreen')}
        </Button>
    );
}
