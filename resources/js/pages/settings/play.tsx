import { Form, Head } from '@inertiajs/react';
import { Play } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import PlaySettingsController from '@/actions/App/Http/Controllers/Settings/PlaySettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { edit } from '@/routes/play-settings';

/**
 * The moments an account chooses a sound for, `Sound::choosable()`; a
 * pack's `arrive` and `leave` are its own and never a choice (§3 "Sounds").
 */
type Moment = 'hit' | 'miss' | 'solved';

const MOMENTS: Moment[] = ['hit', 'miss', 'solved'];

type SoundOption = {
    key: string;
    title: string;
    url: string;
    /** False for a pop: a pack whose items sparkle keeps its own sound (§8). */
    every_pack: boolean;
};

type BoardSounds = {
    chosen: Record<Moment, string | null>;
    options: Record<Moment, SoundOption[]>;
};

/** What the account's boards say (§3 "Voice", ADR 015, ADR 016); all on by default. */
type BoardVoice = {
    prompt: boolean;
    praise: boolean;
    names: boolean;
};

/**
 * Settings → Oyun: whether the teacher board offers the phone remote
 * (docs/architecture.md §4 "Pairing"; off, the board shows no QR code and
 * no «Pultu qoş» and is played on its own screen; a guest board (`/lovhe`)
 * always works with the phone), the sounds the account's boards play
 * (§3 "Sounds"): for each moment the pack's own or one of
 * `content/sounds.json`, each with a button to hear it here, and what the
 * boards say (§3 "Voice"): the prompt; the praise with the retry phrase,
 * the greeting and the end; and a player's first name from Elifba's name
 * list with them, which needs the praise (ADR 016).
 */
export default function PlaySettings({
    phoneRemote,
    sounds,
    voice,
}: {
    phoneRemote: boolean;
    sounds: BoardSounds;
    voice: BoardVoice;
}) {
    const { t } = useTranslation();
    const [checked, setChecked] = useState(phoneRemote);

    return (
        <>
            <Head title={t('settings.play.title')} />

            <h1 className="sr-only">{t('settings.play.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.play.phone_remote.heading')}
                    description={t('settings.play.phone_remote.description')}
                />

                <Form
                    {...PlaySettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {/* The checkbox sends nothing when unticked; this field always says which. */}
                            <input
                                type="hidden"
                                name="phone_remote"
                                value={checked ? '1' : '0'}
                            />

                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="phone_remote"
                                    className="mt-0.5"
                                    checked={checked}
                                    onCheckedChange={(value) =>
                                        setChecked(value === true)
                                    }
                                    aria-describedby="phone_remote_hint"
                                />
                                <div className="grid gap-1.5">
                                    <Label htmlFor="phone_remote">
                                        {t('settings.play.phone_remote.label')}
                                    </Label>
                                    <p
                                        id="phone_remote_hint"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {checked
                                            ? t(
                                                  'settings.play.phone_remote.on_hint',
                                              )
                                            : t(
                                                  'settings.play.phone_remote.off_hint',
                                              )}
                                    </p>
                                </div>
                            </div>

                            <InputError message={errors.phone_remote} />

                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                {t('common.save')}
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            <SoundSettings sounds={sounds} />

            <VoiceSettings voice={voice} />
        </>
    );
}

function VoiceSettings({ voice }: { voice: BoardVoice }) {
    const { t } = useTranslation();
    const [prompt, setPrompt] = useState(voice.prompt);
    const [praise, setPraise] = useState(voice.praise);
    const [names, setNames] = useState(voice.names);

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('settings.play.voice.heading')}
                description={t('settings.play.voice.description')}
            />

            <Form
                {...PlaySettingsController.updateVoice.form()}
                options={{ preserveScroll: true }}
                className="space-y-6"
            >
                {({ processing, errors }) => (
                    <>
                        {/* An unticked checkbox sends nothing; these fields always say which. */}
                        <input
                            type="hidden"
                            name="prompt"
                            value={prompt ? '1' : '0'}
                        />
                        <input
                            type="hidden"
                            name="praise"
                            value={praise ? '1' : '0'}
                        />
                        <input
                            type="hidden"
                            name="names"
                            value={names ? '1' : '0'}
                        />

                        <VoiceSwitch
                            id="voice_prompt"
                            checked={prompt}
                            onChange={setPrompt}
                            label={t('settings.play.voice.prompt')}
                            hint={t('settings.play.voice.prompt_hint')}
                        />
                        <InputError message={errors.prompt} />

                        <VoiceSwitch
                            id="voice_praise"
                            checked={praise}
                            onChange={setPraise}
                            label={t('settings.play.voice.praise')}
                            hint={t('settings.play.voice.praise_hint')}
                        />
                        <InputError message={errors.praise} />

                        {/* Praise governs all the board says besides the prompt: without it no name is said. */}
                        <VoiceSwitch
                            id="voice_names"
                            checked={names && praise}
                            onChange={setNames}
                            disabled={!praise}
                            label={t('settings.play.voice.names')}
                            hint={t('settings.play.voice.names_hint')}
                        />
                        <InputError message={errors.names} />

                        <Button disabled={processing}>
                            {processing && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

function VoiceSwitch({
    id,
    checked,
    onChange,
    label,
    hint,
    disabled = false,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    hint: string;
    disabled?: boolean;
}) {
    return (
        <div className="group flex items-start gap-3" data-disabled={disabled}>
            <Checkbox
                id={id}
                className="mt-0.5"
                checked={checked}
                disabled={disabled}
                onCheckedChange={(value) => onChange(value === true)}
                aria-describedby={`${id}_hint`}
            />
            <div className="grid gap-1.5">
                <Label htmlFor={id}>{label}</Label>
                <p id={`${id}_hint`} className="text-sm text-muted-foreground">
                    {hint}
                </p>
            </div>
        </div>
    );
}

function SoundSettings({ sounds }: { sounds: BoardSounds }) {
    const { t } = useTranslation();
    const audio = useRef<HTMLAudioElement | null>(null);

    useEffect(() => () => audio.current?.pause(), []);

    const listen = (url: string): void => {
        audio.current?.pause();
        audio.current = new Audio(url);
        void audio.current.play().catch(() => undefined);
    };

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('settings.play.sounds.heading')}
                description={t('settings.play.sounds.description')}
            />

            <Form
                {...PlaySettingsController.updateSounds.form()}
                options={{ preserveScroll: true }}
                className="space-y-6"
            >
                {({ processing, errors }) => (
                    <>
                        {MOMENTS.map((moment) => (
                            <fieldset key={moment} className="grid gap-1">
                                <legend className="mb-2 text-sm font-medium">
                                    {t(`settings.play.sounds.${moment}`)}
                                </legend>

                                <SoundChoice
                                    id={`sound-${moment}-pack`}
                                    name={moment}
                                    value=""
                                    checked={sounds.chosen[moment] === null}
                                    label={t('settings.play.sounds.pack')}
                                />

                                {sounds.options[moment].map((option) => (
                                    <SoundChoice
                                        key={option.key}
                                        id={`sound-${moment}-${option.key}`}
                                        name={moment}
                                        value={option.key}
                                        checked={
                                            sounds.chosen[moment] === option.key
                                        }
                                        label={option.title}
                                        hint={
                                            option.every_pack
                                                ? undefined
                                                : t(
                                                      'settings.play.sounds.not_every_pack',
                                                  )
                                        }
                                        onListen={() => listen(option.url)}
                                        listenLabel={t(
                                            'settings.play.sounds.listen',
                                            { sound: option.title },
                                        )}
                                    />
                                ))}

                                <InputError message={errors[moment]} />
                            </fieldset>
                        ))}

                        <Button disabled={processing}>
                            {processing && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

function SoundChoice({
    id,
    name,
    value,
    checked,
    label,
    hint,
    onListen,
    listenLabel,
}: {
    id: string;
    name: string;
    value: string;
    checked: boolean;
    label: string;
    hint?: string;
    onListen?: () => void;
    listenLabel?: string;
}) {
    return (
        <div className="flex min-h-11 items-center gap-3">
            <input
                type="radio"
                id={id}
                name={name}
                value={value}
                defaultChecked={checked}
                aria-describedby={hint === undefined ? undefined : `${id}-hint`}
                className="size-4 shrink-0 accent-primary"
            />
            <div className="grid min-w-0 flex-1 gap-0.5">
                <Label htmlFor={id} className="font-normal">
                    {label}
                </Label>
                {hint !== undefined && (
                    <p
                        id={`${id}-hint`}
                        className="text-xs text-muted-foreground"
                    >
                        {hint}
                    </p>
                )}
            </div>
            {onListen !== undefined && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-11 shrink-0"
                    onClick={onListen}
                    aria-label={listenLabel}
                    title={listenLabel}
                >
                    <Play />
                </Button>
            )}
        </div>
    );
}

PlaySettings.layout = {
    breadcrumbs: [
        {
            title: 'settings.play.title',
            href: edit(),
        },
    ],
};
