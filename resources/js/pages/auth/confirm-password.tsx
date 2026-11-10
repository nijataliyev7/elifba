import { Form, Head, setLayoutProps } from '@inertiajs/react';
import AuthDivider from '@/components/auth-divider';
import GoogleSignIn from '@/components/google-sign-in';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { confirm as confirmWithGoogle } from '@/routes/google';
import { store } from '@/routes/password/confirm';
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

            <div className="grid gap-6">
                {canConfirmWithGoogle && (
                    <>
                        <GoogleSignIn
                            href={confirmWithGoogle.url()}
                            label={t('auth.google.confirm')}
                        />
                        {hasPassword && (
                            <AuthDivider label={t('auth.google.or')} />
                        )}
                    </>
                )}

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
                                        className="h-11 w-full"
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
            </div>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'auth.confirm.title',
    description: 'auth.confirm.description',
};
