import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import GoogleSignIn from '@/components/google-sign-in';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { confirm as confirmWithGoogle } from '@/routes/google';

export type Props = {
    /** False for a teacher who signed up with Google and set no password. */
    hasPassword: boolean;
    /** Confirmed recently (password, passkey or Google): enough without a password. */
    passwordConfirmed: boolean;
    /** The teacher is linked to Google and this instance offers it (ADR 008). */
    canConfirmWithGoogle: boolean;
};

export default function DeleteUser({
    hasPassword,
    passwordConfirmed,
    canConfirmWithGoogle,
}: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const { t } = useTranslation();
    // Without a password, a fresh confirmation stands in for it (ADR 008).
    const mustConfirmFirst = !hasPassword && !passwordConfirmed;

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('settings.delete.title')}
                description={t('settings.delete.description')}
            />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">
                        {t('settings.delete.warning')}
                    </p>
                    <p className="text-sm">{t('settings.delete.caution')}</p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            {t('settings.delete.button')}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>
                            {t('settings.delete.confirm_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {hasPassword
                                ? t('settings.delete.confirm_description')
                                : t(
                                      'settings.delete.confirm_description_no_password',
                                  )}
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        {hasPassword && (
                                            <>
                                                <Label
                                                    htmlFor="password"
                                                    className="sr-only"
                                                >
                                                    {t('common.password')}
                                                </Label>

                                                <PasswordInput
                                                    id="password"
                                                    name="password"
                                                    ref={passwordInput}
                                                    placeholder={t(
                                                        'common.password',
                                                    )}
                                                    autoComplete="current-password"
                                                />
                                            </>
                                        )}

                                        {mustConfirmFirst && (
                                            <p className="text-sm text-muted-foreground">
                                                {canConfirmWithGoogle
                                                    ? t(
                                                          'settings.delete.confirm_with_google',
                                                      )
                                                    : t(
                                                          'settings.delete.no_password',
                                                      )}
                                            </p>
                                        )}

                                        <InputError message={errors.password} />
                                    </div>

                                    {mustConfirmFirst &&
                                        canConfirmWithGoogle && (
                                            <GoogleSignIn
                                                href={confirmWithGoogle.url()}
                                                label={t('auth.google.confirm')}
                                            />
                                        )}

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                {t('common.cancel')}
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            variant="destructive"
                                            disabled={
                                                processing || mustConfirmFirst
                                            }
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                {t('settings.delete.button')}
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
