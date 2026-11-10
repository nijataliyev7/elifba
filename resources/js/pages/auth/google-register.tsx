import { Form, Head } from '@inertiajs/react';
import GoogleRegistrationController from '@/actions/App/Http/Controllers/Auth/GoogleRegistrationController';
import { LinkedText } from '@/components/app/linked-text';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { privacy } from '@/routes';

type Props = {
    /** The address Google verified; it becomes the account's. */
    email: string;
    /** Google's name for the account, editable; may be empty. */
    name: string;
};

/**
 * The last step of signing up with Google (architecture §7 "Google sign-in"):
 * the name and the same privacy-note agreement as the registration form.
 */
export default function GoogleRegister({ email, name }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.google_register.head')} />
            <Form
                {...GoogleRegistrationController.store.form()}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('auth.fields.email')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={email}
                                    readOnly
                                    aria-describedby="email-note"
                                />
                                <p
                                    id="email-note"
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('auth.google_register.email_note')}
                                </p>
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('auth.fields.name')}
                                </Label>
                                <Input
                                    id="name"
                                    type="text"
                                    name="name"
                                    defaultValue={name}
                                    required
                                    autoFocus
                                    autoComplete="name"
                                    placeholder={t(
                                        'auth.fields.name_placeholder',
                                    )}
                                />
                                <InputError message={errors.name} />
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
                                className="mt-2 w-full"
                                disabled={processing}
                                data-test="google-register-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.register.submit')}
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            {/* Forgets Google's name and address, then the sign-in page. */}
                            <TextLink
                                href={GoogleRegistrationController.destroy()}
                                as="button"
                                className="cursor-pointer"
                            >
                                {t('auth.google_register.cancel')}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

GoogleRegister.layout = {
    title: 'auth.google_register.title',
    description: 'auth.google_register.description',
};
