import { Form, Head } from '@inertiajs/react';
import { LinkedText } from '@/components/app/linked-text';
import AuthDivider from '@/components/auth-divider';
import GoogleSignIn from '@/components/google-sign-in';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login, privacy } from '@/routes';
import { redirect as google } from '@/routes/google';
import { store } from '@/routes/register';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    passwordRules: string;
    /** Whether this instance offers Google sign-in (ADR 008). */
    googleSignIn: boolean;
};

/**
 * Google first, when the instance offers it, then the e-mail form
 * (frontend.md rule 1 "Auth pages"). Tab order is the DOM order.
 */
export default function Register({ passwordRules, googleSignIn }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.register.head')} />

            <div className="flex flex-col gap-6">
                {googleSignIn && (
                    <>
                        <GoogleSignIn
                            href={google.url()}
                            label={t('auth.google.sign_up')}
                        />
                        <AuthDivider label={t('auth.google.or_email')} />
                    </>
                )}

                <Form
                    {...store.form()}
                    resetOnSuccess={['password', 'password_confirmation']}
                    disableWhileProcessing
                    className="grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('auth.fields.name')}
                                </Label>
                                <Input
                                    id="name"
                                    className="h-10"
                                    type="text"
                                    required
                                    autoFocus={!googleSignIn}
                                    autoComplete="name"
                                    name="name"
                                    placeholder={t(
                                        'auth.fields.name_placeholder',
                                    )}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('auth.fields.email')}
                                </Label>
                                <Input
                                    id="email"
                                    className="h-10"
                                    type="email"
                                    required
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {t('common.password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    className="h-10"
                                    required
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder={t('common.password')}
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    {t('auth.fields.password_confirmation')}
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    className="h-10"
                                    required
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder={t(
                                        'auth.fields.password_confirmation',
                                    )}
                                    passwordrules={passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="terms"
                                        name="terms"
                                        value="1"
                                        required
                                        className="mt-0.5"
                                        aria-invalid={
                                            errors.terms ? true : undefined
                                        }
                                    />
                                    <Label
                                        htmlFor="terms"
                                        className="leading-snug font-normal"
                                    >
                                        <span>
                                            <LinkedText
                                                text={t('auth.register.terms')}
                                                href={privacy().url}
                                                target="_blank"
                                                rel="noopener"
                                            />
                                        </span>
                                    </Label>
                                </div>
                                <InputError message={errors.terms} />
                            </div>

                            <Button
                                type="submit"
                                className="mt-1 h-11 w-full"
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.register.submit')}
                            </Button>
                        </>
                    )}
                </Form>

                <p className="text-center text-sm text-muted-foreground">
                    {t('auth.register.have_account')}{' '}
                    <TextLink href={login()}>
                        {t('auth.register.log_in')}
                    </TextLink>
                </p>
            </div>
        </>
    );
}

Register.layout = {
    title: 'auth.register.title',
    description: 'auth.register.description',
};
