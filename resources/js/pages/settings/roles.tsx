import { Form, Head } from '@inertiajs/react';
import RolesController from '@/actions/App/Http/Controllers/Settings/RolesController';
import { roleIcons } from '@/components/app/roles';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { edit } from '@/routes/roles';
import type { Role } from '@/types';

type Props = {
    /** The roles the account holds. */
    held: Role[];
    /** The roles it can still take; empty with both. */
    available: Role[];
};

/**
 * Settings → «Rollar» (architecture §1 "Accounts and roles", ADR 010): the
 * account's roles, and the one it lacks, taken with that role's sentence
 * (§7 "Agreements"). A role is never removed.
 */
export default function Roles({ held, available }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('roles.settings.title')} />

            <h1 className="sr-only">{t('roles.settings.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('roles.settings.held.heading')}
                    description={t('roles.settings.held.description')}
                />

                <ul className="grid gap-3">
                    {held.map((role) => (
                        <li key={role}>
                            <RoleSummary role={role} />
                        </li>
                    ))}
                </ul>
            </div>

            {available.length > 0 && (
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={t('roles.settings.add.heading')}
                        description={t('roles.settings.add.description')}
                    />

                    {available.map((role) => (
                        <AddRole key={role} role={role} />
                    ))}
                </div>
            )}
        </>
    );
}

function RoleSummary({ role }: { role: Role }) {
    const { t } = useTranslation();
    const Icon = roleIcons[role];

    return (
        <div className="flex items-start gap-3 rounded-xl border bg-card p-4">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                <Icon aria-hidden className="size-5" />
            </span>
            <span className="grid min-w-0 gap-0.5">
                <span className="font-medium">{t(`roles.${role}.title`)}</span>
                <span className="text-sm text-muted-foreground">
                    {t(`roles.${role}.description`)}
                </span>
            </span>
        </div>
    );
}

function AddRole({ role }: { role: Role }) {
    const { t } = useTranslation();
    const id = `agreement-${role}`;

    return (
        <Form
            {...RolesController.store.form()}
            disableWhileProcessing
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="role" value={role} />

                    <RoleSummary role={role} />

                    <div className="grid gap-2">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id={id}
                                name="agreement"
                                value="1"
                                required
                                className="mt-0.5"
                                aria-invalid={
                                    errors.agreement ? true : undefined
                                }
                            />
                            <Label
                                htmlFor={id}
                                className="leading-snug font-normal"
                            >
                                {t(`roles.agreement.${role}`)}
                            </Label>
                        </div>
                        <InputError message={errors.agreement} />
                        <InputError message={errors.role} />
                    </div>

                    <Button
                        disabled={processing}
                        data-test={`add-role-${role}`}
                    >
                        {processing && <Spinner />}
                        {t(`roles.settings.add.${role}`)}
                    </Button>
                </>
            )}
        </Form>
    );
}

Roles.layout = {
    breadcrumbs: [
        {
            title: 'roles.settings.title',
            href: edit(),
        },
    ],
};
