import { Form, Head } from '@inertiajs/react';
import AuthDivider from '@/components/auth-divider';
import AuthStatus from '@/components/auth-status';
import GoogleSignIn from '@/components/google-sign-in';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { redirect as google } from '@/routes/google';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    status?: string;
    canResetPassword: boolean;
    /** Whether this instance offers Google sign-in (ADR 008). */
    googleSignIn: boolean;
};

/**
 * Google first, when the instance offers it, then the e-mail form
 * (frontend.md rule 1 "Auth pages"). Tab order is the DOM order.
 */
export default function Login({
    status,
    canResetPassword,
    googleSignIn,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.login.head')} />

            <div className="flex flex-col gap-6">
                {status && <AuthStatus message={status} />}

                {googleSignIn && (
                    <>
                        <GoogleSignIn
                            href={google.url()}
                            label={t('auth.google.sign_in')}
                        />
                        <AuthDivider label={t('auth.google.or_email')} />
                    </>
                )}

                <Form
                    {...store.form()}
                    resetOnSuccess={['password']}
                    className="grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('auth.fields.email')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus={!googleSignIn}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    className="h-10"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {t('common.password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="current-password"
                                    placeholder={t('common.password')}
                                    className="h-10"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                <div className="flex items-center gap-3">
                                    <Checkbox id="remember" name="remember" />
                                    <Label htmlFor="remember">
                                        {t('auth.login.remember')}
                                    </Label>
                                </div>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="text-sm"
                                    >
                                        {t('auth.login.forgot')}
                                    </TextLink>
                                )}
                            </div>

                            <Button
                                type="submit"
                                className="mt-1 h-11 w-full"
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.login.submit')}
                            </Button>
                        </>
                    )}
                </Form>

                <p className="text-center text-sm text-muted-foreground">
                    {t('auth.login.no_account')}{' '}
                    <TextLink href={register()}>
                        {t('auth.login.sign_up')}
                    </TextLink>
                </p>
            </div>
        </>
    );
}

Login.layout = {
    title: 'auth.login.title',
    description: 'auth.login.description',
};
