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
} from 'lucide-react';
import { useMemo, useState, useSyncExternalStore } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { choiceParts } from '@/components/rooms/catalog';
import type { GameSetupOptions } from '@/components/rooms/catalog';
import { SeatNext, SeatPair } from '@/components/rooms/draughts/seat-rows';
import { GameDrawing } from '@/components/rooms/game-drawing';
import {
    GameSettings,
    useRoomChoice,
    useRoomStart,
} from '@/components/rooms/game-settings';
import type { RoomChoice } from '@/components/rooms/game-settings';
import { GameEnd, liveGame, ScreenGame } from '@/components/rooms/kinds';
import { ReadyCount, ReadyMark } from '@/components/rooms/ready-parts';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import {
    ScreenTauntsToggle,
    tauntVoices,
    useTauntsHidden,
} from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import { RoomQr, spacedCode, typedAddress } from '@/components/rooms/room-code';
import type { JoinLink } from '@/components/rooms/room-code';
import { SoundToggle } from '@/components/rooms/sound-toggle';
import { useTauntsSwitch } from '@/components/rooms/taunts-switch';
import { StandingCard } from '@/components/rooms/start-parts';
import { useLiveRoom } from '@/components/rooms/use-live-room';
import { useRoomMotion } from '@/components/rooms/use-motion';
import type { RoomMotion } from '@/components/rooms/use-motion';
import { useRoomSound } from '@/components/rooms/use-room-sound';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { show } from '@/routes/rooms';
import { voice } from '@/routes/rooms/members';
import type {
    RoomGame,
    RoomPlayer,
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
    const [tauntsHidden, setTauntsHidden] = useTauntsHidden();
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
    // A live game: a race's round or a board game's match, drawn by its kind's views (`kinds.ts`).
    const live = liveGame(state);
    const playing = live !== null;
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
                <SoundToggle sound={sound} big />
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
            ) : live !== null ? (
                <main className="flex min-h-0 flex-1 flex-col px-[4vw] py-[3vh]">
                    <ScreenGame
                        live={live}
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
    const choice = useRoomChoice({
        roomId,
        choice: setup,
        onChanged: refresh,
    });
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
                players={state.players}
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
 * whom the host muted says so on the chip, an icon with its word (C4). A
 * player whose «Hazıram» counts has `Check` and «Hazır» (ADR 035).
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
                    {player.ready && <ReadyMark big />}
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
    const present = state.players.filter((player) => player.present).length;
    const shown = choice.shown;
    const parts = choiceParts(t, shown);
    const needs = parts.needs;

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
                    <span className="flex min-w-0 items-center gap-[2vh] text-[max(0.9375rem,2.4vh)] text-muted-foreground">
                        <span className="shrink-0">{parts.line}</span>
                        {parts.second === 'seats' && (
                            <SeatPair
                                seats={parts.seats}
                                players={state.players}
                                big
                            />
                        )}
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
                {needs !== null && (
                    <span className="max-w-[20vw] text-[max(0.875rem,1.9vh)] text-muted-foreground">
                        {t(needs)}
                    </span>
                )}
                <ReadyCount
                    players={state.players}
                    className="shrink-0 text-[max(0.9375rem,2.2vh)]"
                />
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
                        choice.pending !== null ||
                        needs !== null
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
 * the left with who has joined under it, each ready player marked (ADR
 * 035), and a right column with «Yenidən oyna», how many are ready,
 * «Başqa oyun:» over the other games and the code for whoever comes next.
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
    const parts = choiceParts(t, choice.shown);
    const present = state.players.filter((player) => player.present).length;

    return (
        <main
            data-motion={motion.phase ? 'on' : undefined}
            className="grid flex-1 grid-cols-[minmax(0,1fr)_27vw] gap-[4vw] px-[4vw] py-[3vh]"
        >
            <div className="flex min-w-0 flex-col gap-[3vh]">
                <GameEnd
                    state={state}
                    game={game}
                    placeLabels={placeLabels}
                    motion={motion}
                    big
                />
                {/* Who is ready for the next game, under the final where the row is wide (ADR 035). */}
                <section className="flex flex-col gap-[1.2vh]">
                    <h2 className="text-[max(1rem,2.6vh)] font-semibold">
                        {t('rooms.start.joined_count', { count: present })}
                    </h2>
                    <PlayerChips players={state.players} />
                </section>
            </div>

            <aside className="flex min-w-0 flex-col gap-[1.6vh] self-start">
                <Button
                    type="button"
                    className="h-[max(3.25rem,9vh)] text-[max(1.125rem,3vh)]"
                    disabled={busy || parts.needs !== null}
                    onClick={start.start}
                >
                    {start.starting ? (
                        <Spinner className="size-[max(1.25rem,3vh)]" />
                    ) : (
                        <RotateCcw className="size-[max(1.25rem,3vh)]" />
                    )}
                    {t('rooms.start.replay')}
                </Button>
                {parts.second === 'seats' && (
                    <SeatNext
                        seats={parts.seats}
                        players={state.players}
                        className="text-[max(0.9375rem,2.2vh)] text-muted-foreground"
                    />
                )}
                {/* How many are ready for the next game, by the start (ADR 035). */}
                <ReadyCount
                    players={state.players}
                    className="justify-start text-[max(0.9375rem,2.2vh)]"
                />
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
                                    onClick={() => choice.pick(one)}
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
