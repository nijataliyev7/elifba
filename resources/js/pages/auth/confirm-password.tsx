import { Form, Head, setLayoutProps } from '@inertiajs/react';
import GoogleSignIn from '@/components/google-sign-in';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { confirm as confirmWithGoogle } from '@/routes/google';
import { store } from '@/routes/password/confirm';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import PasskeyVerify from '@/components/passkey-verify';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    /** False for a teacher who signed up with Google and set no password. */
    hasPassword: boolean;
    /** The teacher is linked to Google and this instance offers it (ADR 008). */
    canConfirmWithGoogle: boolean;
};

export default function ConfirmPassword({
    hasPassword,
    canConfirmWithGoogle,
}: Props) {
    const { t } = useTranslation();

    // Without a password the page does not ask for one (ADR 008).
    setLayoutProps(
        hasPassword
            ? {
                  title: 'auth.confirm.title',
                  description: 'auth.confirm.description',
              }
            : {
                  title: 'auth.confirm.identity_title',
                  description: canConfirmWithGoogle
                      ? 'auth.confirm.with_google_description'
                      : 'auth.confirm.no_password',
              },
    );

    return (
        <>
            <Head
                title={t(
                    hasPassword
                        ? 'auth.confirm.title'
                        : 'auth.confirm.identity_title',
                )}
            />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label={t('auth.confirm.with_passkey')}
                loadingLabel={t('auth.confirm.confirming')}
                separator={hasPassword ? t('auth.confirm.or_password') : false}
            />

            {hasPassword && (
                <Form {...store.form()} resetOnSuccess={['password']}>
                    {({ processing, errors }) => (
                        <div className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {t('common.password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    placeholder={t('common.password')}
                                    autoComplete="current-password"
                                    autoFocus
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center">
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                    data-test="confirm-password-button"
                                >
                                    {processing && <Spinner />}
                                    {t('auth.confirm.submit')}
                                </Button>
                            </div>
                        </div>
                    )}
                </Form>
            )}

            {canConfirmWithGoogle && (
                <GoogleSignIn
                    href={confirmWithGoogle.url()}
                    label={t('auth.google.confirm')}
                    separator={hasPassword ? t('auth.google.or') : undefined}
                />
            )}
        </>
    );
}

ConfirmPassword.layout = {
    title: 'auth.confirm.title',
    description: 'auth.confirm.description',
};
