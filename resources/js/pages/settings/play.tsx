import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
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
 * Settings → Oyun (docs/architecture.md §4 "Pairing"): whether the teacher
 * board offers the phone remote. Off, the board shows no QR code and no
 * «Pultu qoş» and is played on its own screen; a guest board (`/lovhe`)
 * always works with the phone.
 */
export default function PlaySettings({
    phoneRemote,
}: {
    phoneRemote: boolean;
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
        </>
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
