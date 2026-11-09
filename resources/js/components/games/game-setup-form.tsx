import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Toggle } from '@/components/ui/toggle';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { GameSetupProps, SetupOption } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

export type GameSetupFormProps = Omit<GameSetupProps, 'schoolClass'> & {
    /** Where the setup goes: the setup page's `StartGame`, or a guest board from the phone. */
    form: RouteFormDefinition<'post'>;
    /** Fields the form sends as they are (the class, for a guest board); their errors show at the top. */
    hidden?: Record<string, string>;
    submitLabel?: string;
};

/**
 * The game setup form (docs/architecture.md §2, "Game setup"): a theme
 * pack, a level, the number of rounds, the letter case and, for the letter
 * level, which letters to ask for. What can start comes from the server's
 * `options` (`GameSetupOptions`) — the same check `GameSetupData::rules()`
 * makes — so a pack the board cannot draw yet is shown as coming soon and a
 * level the class lacks content for shows the server's reason. Errors come
 * back on their fields. The setup page and the phone's guest board pages
 * (`/pult/{code}`, `/guest-boards/{board}/game`) use it alike.
 */
export function GameSetupForm({
    packs,
    levels,
    options,
    letters,
    letterCases,
    rounds,
    form,
    hidden = {},
    submitLabel,
}: GameSetupFormProps) {
    const { t } = useTranslation();

    const drawable = (pack: string) =>
        options.some((option) => option.pack === pack && option.drawable);
    const startableIn = (pack: string) =>
        options.find((option) => option.pack === pack && option.startable);

    const firstPack =
        packs.find((pack) => startableIn(pack.key))?.key ??
        packs.find((pack) => drawable(pack.key))?.key ??
        '';

    const [pack, setPack] = useState(firstPack);
    const mechanics = [
        ...new Set(
            options
                .filter((option) => option.pack === pack && option.drawable)
                .map((option) => option.mechanic),
        ),
    ];
    const [chosenMechanic, setMechanic] = useState(
        startableIn(firstPack)?.mechanic ?? mechanics[0] ?? 'find',
    );
    const mechanic = mechanics.includes(chosenMechanic)
        ? chosenMechanic
        : (mechanics[0] ?? chosenMechanic);

    const levelOptions = levels
        .map((level) => ({
            level,
            option: options.find(
                (option) =>
                    option.pack === pack &&
                    option.mechanic === mechanic &&
                    option.level === level.key,
            ),
        }))
        .filter(
            (entry): entry is typeof entry & { option: SetupOption } =>
                entry.option !== undefined,
        );

    const [chosenLevel, setLevel] = useState(
        startableIn(firstPack)?.level ?? '',
    );
    const level =
        levelOptions.find(
            (entry) =>
                entry.level.key === chosenLevel && entry.option.startable,
        )?.level ??
        levelOptions.find((entry) => entry.option.startable)?.level ??
        null;
    const letterLevel = level?.unit === 'letter';

    // The server's default (capitals for letters, as written otherwise)
    // until the teacher picks one.
    const [chosenCase, setCase] = useState<'upper' | 'lower' | null>(null);
    const letterCase = chosenCase ?? (letterLevel ? 'upper' : 'lower');

    const [roundCount, setRoundCount] = useState(String(rounds.default));
    const [asked, setAsked] = useState<Set<string>>(() => new Set());

    const toggleLetter = (letter: string, pressed: boolean) => {
        setAsked((current) => {
            const next = new Set(current);

            if (pressed) {
                next.add(letter);
            } else {
                next.delete(letter);
            }

            return next;
        });
    };

    return (
        <>
            {!options.some((option) => option.startable) && (
                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    {t('games.setup.none')}
                </p>
            )}

            <Form {...form} className="flex flex-col gap-8">
                {({ processing, errors }) => (
                    <>
                        {Object.entries(hidden).map(([name, value]) => (
                            <input
                                key={name}
                                type="hidden"
                                name={name}
                                value={value}
                            />
                        ))}
                        <InputError
                            message={
                                Object.entries(errors).find(([name]) =>
                                    Object.hasOwn(hidden, name),
                                )?.[1]
                            }
                        />
                        <input type="hidden" name="mechanic" value={mechanic} />
                        <input
                            type="hidden"
                            name="letter_case"
                            value={letterCase}
                        />
                        {letterLevel &&
                            letters
                                .filter((letter) => asked.has(letter.lower))
                                .map((letter) => (
                                    <input
                                        key={letter.lower}
                                        type="hidden"
                                        name="letters[]"
                                        value={letter.lower}
                                    />
                                ))}

                        <fieldset className="grid gap-3">
                            <legend className="mb-3 text-sm font-medium">
                                {t('games.setup.pack')}
                            </legend>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {packs.map((item) => {
                                    const ready = drawable(item.key);

                                    return (
                                        <label
                                            key={item.key}
                                            className={cn(
                                                'flex min-w-0 cursor-pointer items-center gap-3 rounded-xl border p-4 transition-colors has-[input:checked]:border-primary has-[input:checked]:bg-accent has-[input:focus-visible]:ring-2 has-[input:focus-visible]:ring-ring',
                                                !ready &&
                                                    'cursor-not-allowed opacity-60',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="pack"
                                                value={item.key}
                                                checked={pack === item.key}
                                                disabled={!ready}
                                                onChange={() =>
                                                    setPack(item.key)
                                                }
                                                className="sr-only"
                                            />
                                            <span
                                                aria-hidden
                                                className="flex shrink-0 -space-x-1.5"
                                            >
                                                {item.style.palette
                                                    .slice(0, 4)
                                                    .map((colour) => (
                                                        <span
                                                            key={colour}
                                                            className="size-5 rounded-full border-2 border-background"
                                                            style={{
                                                                backgroundColor:
                                                                    colour,
                                                            }}
                                                        />
                                                    ))}
                                            </span>
                                            <span className="flex min-w-0 flex-1 flex-col items-start gap-1">
                                                <span className="max-w-full truncate font-medium">
                                                    {item.title}
                                                </span>
                                                {!ready && (
                                                    <Badge variant="secondary">
                                                        {t(
                                                            'games.setup.coming_soon',
                                                        )}
                                                    </Badge>
                                                )}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                            <InputError message={errors.pack} />
                        </fieldset>

                        {mechanics.length > 1 && (
                            <fieldset className="grid gap-3">
                                <legend className="mb-3 text-sm font-medium">
                                    {t('games.setup.mechanic')}
                                </legend>
                                <div className="flex flex-wrap gap-2">
                                    {mechanics.map((item) => (
                                        <Button
                                            key={item}
                                            type="button"
                                            variant={
                                                item === mechanic
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            aria-pressed={item === mechanic}
                                            onClick={() => setMechanic(item)}
                                        >
                                            {t(`games.mechanics.${item}`)}
                                        </Button>
                                    ))}
                                </div>
                            </fieldset>
                        )}
                        <InputError message={errors.mechanic} />

                        <fieldset className="grid gap-3">
                            <legend className="mb-3 text-sm font-medium">
                                {t('games.setup.level')}
                            </legend>
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {levelOptions.map(({ level: item, option }) => (
                                    <label
                                        key={item.key}
                                        className={cn(
                                            'flex min-w-0 cursor-pointer flex-col gap-1 rounded-lg border p-3 transition-colors has-[input:checked]:border-primary has-[input:checked]:bg-accent has-[input:focus-visible]:ring-2 has-[input:focus-visible]:ring-ring',
                                            !option.startable &&
                                                'cursor-not-allowed opacity-70',
                                        )}
                                    >
                                        <span className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                name="level"
                                                value={item.key}
                                                checked={
                                                    level?.key === item.key
                                                }
                                                disabled={!option.startable}
                                                onChange={() =>
                                                    setLevel(item.key)
                                                }
                                                className="size-4 accent-primary"
                                            />
                                            <span className="truncate font-medium">
                                                {item.title}
                                            </span>
                                        </span>
                                        {option.reason !== null && (
                                            <span className="text-xs text-muted-foreground">
                                                {option.reason}
                                            </span>
                                        )}
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.level} />
                        </fieldset>

                        {letterLevel && (
                            <fieldset className="grid gap-3">
                                <legend className="mb-1 text-sm font-medium">
                                    {t('games.setup.letters')}
                                </legend>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-sm text-muted-foreground">
                                        {t('games.setup.letters_hint')}
                                    </p>
                                    {asked.size > 0 && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setAsked(new Set())}
                                        >
                                            {t('games.setup.letters_clear')}
                                        </Button>
                                    )}
                                </div>
                                <div
                                    role="group"
                                    aria-label={t('games.setup.letters')}
                                    className="flex flex-wrap gap-2"
                                >
                                    {letters.map((letter) => (
                                        <Toggle
                                            key={letter.lower}
                                            variant="outline"
                                            lang="az"
                                            pressed={asked.has(letter.lower)}
                                            onPressedChange={(pressed) =>
                                                toggleLetter(
                                                    letter.lower,
                                                    pressed,
                                                )
                                            }
                                            className="h-11 min-w-11 font-board text-lg data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                                        >
                                            {letterCase === 'upper'
                                                ? letter.upper
                                                : letter.lower}
                                        </Toggle>
                                    ))}
                                </div>
                                <InputError
                                    message={
                                        errors.letters ??
                                        Object.entries(errors).find(([key]) =>
                                            key.startsWith('letters.'),
                                        )?.[1]
                                    }
                                />
                            </fieldset>
                        )}

                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="rounds">
                                    {t('games.setup.rounds')}
                                </Label>
                                <Input
                                    id="rounds"
                                    name="rounds"
                                    type="number"
                                    inputMode="numeric"
                                    min={rounds.min}
                                    max={rounds.max}
                                    value={roundCount}
                                    onChange={(event) =>
                                        setRoundCount(event.target.value)
                                    }
                                    className="max-w-32"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('games.setup.rounds_hint', {
                                        min: rounds.min,
                                        max: rounds.max,
                                    })}
                                </p>
                                <InputError message={errors.rounds} />
                            </div>

                            <fieldset className="grid content-start gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    {t('games.setup.letter_case')}
                                </legend>
                                <div className="flex flex-wrap gap-2">
                                    {letterCases.map((item) => (
                                        <Button
                                            key={item}
                                            type="button"
                                            variant={
                                                item === letterCase
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            aria-pressed={item === letterCase}
                                            onClick={() => setCase(item)}
                                        >
                                            {t(
                                                `games.setup.letter_cases.${item}`,
                                            )}
                                        </Button>
                                    ))}
                                </div>
                                <InputError message={errors.letter_case} />
                            </fieldset>
                        </div>

                        <div>
                            <Button
                                type="submit"
                                size="lg"
                                disabled={processing || level === null}
                            >
                                {processing && <Spinner />}
                                {submitLabel ?? t('games.setup.start')}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
