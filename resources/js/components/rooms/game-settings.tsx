import { X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    choiceBody,
    choiceParts,
    clockLevelText,
    gameOf,
    levelOf,
    roundSteps,
    sameChoice,
    withGame,
} from '@/components/rooms/catalog';
import type {
    CatalogGame,
    GameSetupOptions,
    SetupChoice,
    SetupLevel,
} from '@/components/rooms/catalog';
import { SeatRows } from '@/components/rooms/draughts/seat-rows';
import { GameDrawing } from '@/components/rooms/game-drawing';
import { seconds } from '@/components/rooms/seconds';
import { TauntsSwitchRow } from '@/components/rooms/taunts-switch';
import type { TauntsSwitch } from '@/components/rooms/taunts-switch';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { store as startGame } from '@/routes/rooms/games';
import { update as chooseGame } from '@/routes/rooms/setup';
import type { RoomPlayer } from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';

/** Which control a tap went through: it shows pressed while the others wait. */
export type ChoiceControl = 'mechanic' | 'difficulty' | 'rounds' | 'seats';

/** The room's choice as a page shows it and changes it (`useRoomChoice()`). */
export type RoomChoice = {
    /** The state's choice, or the tap not yet in the state. */
    shown: SetupChoice;
    /** The control of a tap the server has not answered yet. */
    pending: ChoiceControl | null;
    failed: boolean;
    choose: (next: SetupChoice, control: ChoiceControl) => void;
    /** Another game, with what a choice of its kind needs (`withGame()`). */
    pick: (game: CatalogGame) => void;
};

/**
 * The host's choice of the next game (ADR 026 decision 8, docs/frontend.md
 * rule 15 "Oyun ayarları"): a tap sends the whole choice to
 * `rooms.setup.update` at once; the tapped control shows its value at
 * once and every control waits, disabled, until the server answers. The
 * choice is the room's: after a 204 the page asks for the state, and the
 * tap stands only until the state's choice moves — the state then says
 * what was chosen, on this device and every other. A refusal or a failure
 * is `rooms.setup.change_failed`, and the state's value stands again.
 */
export function useRoomChoice({
    roomId,
    choice,
    players,
    options,
    onChanged,
}: {
    roomId: string;
    /** The room's choice, from the state. */
    choice: SetupChoice;
    /** The room's players, in the order they joined: who a board game may seat. */
    players: RoomPlayer[];
    /** The catalog: the rounds' bounds. */
    options: GameSetupOptions;
    /** After a write: the page asks for the state now rather than wait for the signal. */
    onChanged: () => void;
}): RoomChoice {
    const key = `${choice.mechanic}:${choice.difficulty}:${choice.rounds}:${choice.white}:${choice.black}`;
    // A board game's choice has no rounds: a race chosen after it takes the last the page saw.
    const [rounds, setRounds] = useState<number | null>(choice.rounds ?? null);

    if (choice.rounds !== undefined && choice.rounds !== rounds) {
        setRounds(choice.rounds);
    }

    // The tap, and the state's choice it was made over: once that moves, the state has answered.
    const [tap, setTap] = useState<{
        choice: SetupChoice;
        over: string;
    } | null>(null);
    const [pending, setPending] = useState<ChoiceControl | null>(null);
    const [failed, setFailed] = useState(false);
    const shown = tap !== null && tap.over === key ? tap.choice : choice;

    const choose = (next: SetupChoice, control: ChoiceControl) => {
        if (pending !== null || sameChoice(next, shown)) {
            return;
        }

        setTap({ choice: next, over: key });
        setPending(control);
        setFailed(false);

        void roomRequest('PUT', chooseGame.url(roomId), choiceBody(next))
            .then((reply) => {
                if (reply.status !== 204) {
                    // 403: a game started meanwhile; 422: not a level the room plays. The state says what is.
                    setFailed(true);
                    setTap(null);
                }
            })
            .catch(() => {
                setFailed(true);
                setTap(null);
            })
            .finally(() => {
                setPending(null);
                onChanged();
            });
    };

    const steps = roundSteps(options.rounds);
    const pick = (game: CatalogGame) =>
        choose(
            withGame(shown, game, {
                rounds: rounds ?? steps[Math.floor(steps.length / 2)],
                present: players
                    .filter((player) => player.present)
                    .map((player) => player.id),
            }),
            'mechanic',
        );

    return { shown, pending, failed, choose, pick };
}

/**
 * The host's start (ADR 026 decision 9): `rooms.games.store` with no body,
 * so what starts is the room's choice every device was shown. A 403 is a
 * start that lost to another device of the host, or a game already
 * running: the state says which.
 */
export function useRoomStart({
    roomId,
    onChanged,
}: {
    roomId: string;
    onChanged: () => void;
}): { starting: boolean; failed: boolean; start: () => void } {
    const [starting, setStarting] = useState(false);
    const [failed, setFailed] = useState(false);

    const start = () => {
        if (starting) {
            return;
        }

        setStarting(true);
        setFailed(false);

        void roomRequest('POST', startGame.url(roomId))
            .then((reply) => {
                if (reply.status !== 204 && reply.status !== 403) {
                    setFailed(true);
                }
            })
            .catch(() => setFailed(true))
            .finally(() => {
                setStarting(false);
                onChanged();
            });
    };

    return { starting, failed, start };
}

const pressable =
    'outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait motion-reduce:transition-none';

/**
 * The six games as buttons (docs/frontend.md rule 15 "Oyun ayarları"): on
 * a phone two columns of 52px with the drawing and the short name; on the
 * screen (`big`) three by two with the full names. `aria-pressed` is the
 * shown choice's game.
 */
export function GamePicker({
    options,
    choice,
    big = false,
}: {
    options: GameSetupOptions;
    choice: RoomChoice;
    big?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div
            className={cn(
                'grid gap-2',
                big ? 'grid-cols-3 gap-[1.4vh]' : 'grid-cols-2',
            )}
        >
            {options.mechanics.map((game) => {
                const pressed = game.mechanic === choice.shown.mechanic;

                return (
                    <button
                        key={game.mechanic}
                        type="button"
                        aria-pressed={pressed}
                        disabled={choice.pending !== null}
                        onClick={() => choice.pick(game)}
                        className={cn(
                            pressable,
                            'flex items-center gap-2.5 rounded-xl border bg-card text-left',
                            big
                                ? 'min-h-[max(3.25rem,8vh)] gap-[1.4vh] px-[1.6vh] py-[1vh] text-[max(1rem,2.2vh)]'
                                : 'min-h-13 px-2.5 py-1.5 text-sm',
                            pressed
                                ? 'border-primary bg-accent ring-1 ring-primary'
                                : 'hover:bg-accent/60',
                            !pressed && choice.pending !== null && 'opacity-60',
                        )}
                    >
                        <GameDrawing
                            mechanic={game.mechanic}
                            className={
                                big
                                    ? 'text-[max(2.25rem,5.5vh)]'
                                    : 'text-[36px]'
                            }
                        />
                        <span className="min-w-0 flex-1 leading-tight font-semibold">
                            {big
                                ? t(`rooms.mechanics.${game.mechanic}.name`)
                                : t(`rooms.mechanics.${game.mechanic}.short`)}
                        </span>
                        {pressed && choice.pending === 'mechanic' && (
                            <Spinner />
                        )}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * «Çətinlik»: four segments over one muted line of what the level holds,
 * from the server's `levels`; a board game's «Vaxt» (ADR 034 decision 6):
 * each segment its level's clock («5+3», tabular), never «Asan».
 */
export function DifficultySegments({
    game,
    choice,
    big = false,
}: {
    game: CatalogGame;
    choice: RoomChoice;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const level = levelOf(game, choice.shown.difficulty);

    return (
        <div className={cn('flex flex-col gap-2', big && 'gap-[1.2vh]')}>
            <div className="grid grid-cols-4 gap-1 rounded-xl bg-muted p-1">
                {game.difficulties.map((difficulty) => {
                    const pressed = difficulty === choice.shown.difficulty;
                    const clock = levelOf(game, difficulty)?.clock;

                    return (
                        <button
                            key={difficulty}
                            type="button"
                            aria-pressed={pressed}
                            disabled={choice.pending !== null}
                            onClick={() =>
                                choice.choose(
                                    { ...choice.shown, difficulty },
                                    'difficulty',
                                )
                            }
                            className={cn(
                                pressable,
                                'rounded-lg px-1 leading-tight font-medium',
                                big
                                    ? 'min-h-[max(2.75rem,6vh)] text-[max(0.9375rem,2vh)]'
                                    : 'min-h-11 text-[0.8125rem]',
                                pressed
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground',
                                clock !== undefined && 'tabular-nums',
                            )}
                        >
                            {clock !== undefined
                                ? clockLevelText(t, clock)
                                : t(`rooms.difficulty.${difficulty}`)}
                        </button>
                    );
                })}
            </div>
            {level !== undefined && (
                <LevelLine
                    level={level}
                    className={big ? 'text-[max(0.875rem,1.9vh)]' : undefined}
                />
            )}
        </div>
    );
}

/** «Raund sayı»: one button per step the server's bounds allow, never a typed number. */
export function RoundStepButtons({
    options,
    choice,
    big = false,
}: {
    options: GameSetupOptions;
    choice: RoomChoice;
    big?: boolean;
}) {
    const steps = roundSteps(options.rounds);

    return (
        <div
            className={cn('grid gap-2', big && 'gap-[1vh]')}
            style={{
                gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))`,
            }}
        >
            {steps.map((step) => {
                const pressed = step === choice.shown.rounds;

                return (
                    <Button
                        key={step}
                        type="button"
                        variant={pressed ? 'default' : 'outline'}
                        aria-pressed={pressed}
                        disabled={choice.pending !== null}
                        className={cn(
                            'tabular-nums disabled:cursor-wait',
                            big
                                ? 'h-[max(2.75rem,6vh)] text-[max(1rem,2.2vh)]'
                                : 'h-11 text-base',
                            pressed && 'disabled:opacity-100',
                        )}
                        onClick={() =>
                            choice.choose(
                                { ...choice.shown, rounds: step },
                                'rounds',
                            )
                        }
                    >
                        {step}
                    </Button>
                );
            })}
        </div>
    );
}

/** What a level holds, in one muted line («5–6 element · 4,5 san. baxmaq · 15 san. cavab»). */
export function LevelLine({
    level,
    className,
}: {
    level: SetupLevel;
    className?: string;
}) {
    const { t } = useTranslation();
    const mark = t('rooms.decimal_mark');
    const answer = seconds(level.answer_ms, mark);
    let line: string | null = null;

    if (level.clock !== undefined) {
        line = t('rooms.clock.line', {
            minutes: Math.round(level.clock.base_ms / 60_000),
            seconds: Math.round(level.clock.increment_ms / 1000),
        });
    } else if (level.rules !== null) {
        line = t('rooms.setup.levels.sequence', {
            terms: level.terms ?? 0,
            min: level.range?.min ?? 0,
            max: level.range?.max ?? 0,
            rules: level.rules
                .map((rule) => t(`rooms.setup.rules.${rule}`))
                .join(', '),
            answer,
        });
    } else if (level.source !== null) {
        line = t('rooms.setup.levels.odd', {
            options: level.options ?? 0,
            source: t(`rooms.setup.sources.${level.source}`),
            answer,
        });
    } else if (level.letters !== null) {
        line = t(
            level.swaps === null
                ? 'rooms.setup.levels.letters'
                : `rooms.setup.levels.spelling_${level.swaps}`,
            { min: level.letters.min, max: level.letters.max, answer },
        );
    } else if (level.count !== null) {
        const study = seconds(level.study_ms, mark);

        line =
            level.grid !== null
                ? t('rooms.setup.levels.grid', {
                      cols: level.grid.cols,
                      rows: level.grid.rows,
                      min: level.count.min,
                      max: level.count.max,
                      study,
                      answer,
                  })
                : t('rooms.setup.levels.row', {
                      min: level.count.min,
                      max: level.count.max,
                      study,
                      answer,
                  });
    }

    if (line === null) {
        return null;
    }

    return (
        <p
            className={cn(
                'text-[0.8125rem] leading-snug text-muted-foreground tabular-nums',
                className,
            )}
        >
            {line}
        </p>
    );
}

/**
 * «Oyun ayarları» (`GameSettings`, docs/frontend.md rule 15; ADR 026
 * decisions 8 and 16–17): the game, the difficulty and the rounds of the
 * room's choice — a board game's clock and its two seats (`SeatRows`,
 * ADR 034 decision 27) — each tap sent as the whole choice (`useRoomChoice()`), and
 * «Hazırdır», which closes. On a phone a sheet from the bottom below `sm`
 * and a dialog from there; on the screen (`big`) a centred dialog with the
 * games three by two and «Çətinlik» beside «Raund sayı»; from T2 the
 * host's «Atmacalar» under them (ADR 029 decision 12). Starting is not
 * here: it is the bar's.
 */
export function GameSettings({
    options,
    choice,
    open,
    onOpenChange,
    players,
    big = false,
    taunts,
}: {
    options: GameSetupOptions;
    choice: RoomChoice;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The room's players: a board game's seats are chosen among the present ones. */
    players: RoomPlayer[];
    big?: boolean;
    /** The host's switch «Atmacalar» (T2), under the choice. */
    taunts?: TauntsSwitch;
}) {
    const { t } = useTranslation();
    const game = gameOf(options, choice.shown.mechanic);
    const parts = choiceParts(t, choice.shown);

    if (game === undefined) {
        return null;
    }

    const legend = cn(
        'font-semibold',
        big ? 'mb-[1vh] text-[max(1rem,2.2vh)]' : 'mb-1.5 text-sm',
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                showCloseButton={false}
                aria-describedby={undefined}
                className={cn(
                    'flex flex-col gap-0 overflow-hidden p-0 motion-reduce:animate-none',
                    big
                        ? 'max-h-[92dvh] w-[min(92vw,72rem)] max-w-none rounded-2xl sm:max-w-none'
                        : 'top-auto bottom-0 left-0 max-h-[92dvh] max-w-none translate-x-0 translate-y-0 rounded-none rounded-t-2xl border-x-0 border-b-0 data-[state=closed]:zoom-out-100 data-[state=open]:zoom-in-100 sm:top-[50%] sm:bottom-auto sm:left-[50%] sm:max-w-lg sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-2xl sm:border',
                )}
            >
                <div
                    className={cn(
                        'flex items-center gap-3 border-b',
                        big ? 'px-[3vh] py-[1.6vh]' : 'px-4 py-2',
                    )}
                >
                    <DialogTitle
                        className={cn(
                            'min-w-0 flex-1',
                            big ? 'text-[max(1.25rem,3.2vh)]' : 'text-lg',
                        )}
                    >
                        {t('rooms.setup.title')}
                    </DialogTitle>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            className={cn(
                                'shrink-0 rounded-full',
                                big ? 'size-[max(2.75rem,6vh)]' : 'size-11',
                            )}
                            aria-label={t('common.close')}
                        >
                            <X className="size-5" />
                        </Button>
                    </DialogClose>
                </div>

                <div
                    className={cn(
                        'flex min-h-0 flex-1 flex-col overflow-y-auto',
                        big
                            ? 'gap-[3vh] px-[3vh] py-[2.4vh]'
                            : 'gap-5 px-4 py-3',
                    )}
                >
                    <fieldset className="flex flex-col gap-2">
                        <legend className={legend}>
                            {t('rooms.setup.game')}
                        </legend>
                        <GamePicker
                            options={options}
                            choice={choice}
                            big={big}
                        />
                        <p
                            className={cn(
                                'leading-snug text-muted-foreground',
                                big
                                    ? 'text-[max(0.875rem,1.9vh)]'
                                    : 'text-[0.8125rem]',
                            )}
                        >
                            {t(`rooms.mechanics.${game.mechanic}.line`)}
                        </p>
                    </fieldset>

                    <div
                        className={cn(
                            'flex flex-col gap-5',
                            big && 'grid grid-cols-2 gap-[3vh]',
                        )}
                    >
                        <fieldset className="flex min-w-0 flex-col">
                            <legend className={legend}>
                                {parts.second === 'seats'
                                    ? t('rooms.clock.label')
                                    : t('rooms.setup.difficulty')}
                            </legend>
                            <DifficultySegments
                                game={game}
                                choice={choice}
                                big={big}
                            />
                        </fieldset>
                        <fieldset className="flex min-w-0 flex-col">
                            <legend className={legend}>
                                {parts.second === 'seats'
                                    ? t('rooms.seats.title')
                                    : t('rooms.setup.rounds')}
                            </legend>
                            {parts.second === 'seats' ? (
                                <SeatRows
                                    seats={parts.seats}
                                    players={players}
                                    choice={choice}
                                    big={big}
                                />
                            ) : (
                                <RoundStepButtons
                                    options={options}
                                    choice={choice}
                                    big={big}
                                />
                            )}
                        </fieldset>
                    </div>

                    {taunts !== undefined && (
                        <div className="border-t">
                            <TauntsSwitchRow taunts={taunts} big={big} />
                        </div>
                    )}
                </div>

                <div
                    className={cn(
                        'flex flex-col gap-2 border-t',
                        big
                            ? 'px-[3vh] py-[1.8vh]'
                            : 'px-4 pt-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]',
                    )}
                >
                    {(choice.failed || (taunts?.failed ?? false)) && (
                        <InputError message={t('rooms.setup.change_failed')} />
                    )}
                    <DialogClose asChild>
                        <Button
                            type="button"
                            className={cn(
                                'w-full',
                                big
                                    ? 'h-[max(3.25rem,7vh)] text-[max(1rem,2.4vh)]'
                                    : 'h-13 text-base',
                            )}
                        >
                            {t('rooms.setup.done')}
                        </Button>
                    </DialogClose>
                </div>
            </DialogContent>
        </Dialog>
    );
}
