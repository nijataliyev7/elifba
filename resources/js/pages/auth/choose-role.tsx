import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import RoleController from '@/actions/App/Http/Controllers/RoleController';
import { roleIcons } from '@/components/app/roles';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import type { Role } from '@/types';

/**
 * The role choice right after registration (architecture §1 "Accounts and
 * roles", ADR 010): «Müəllim» or «Valideyn», each with one sentence about
 * what it is for, then that role's agreement (§7 "Agreements"). The other
 * role can be added later in Settings → «Rollar».
 */
export default function ChooseRole({ roles }: { roles: Role[] }) {
    const { t } = useTranslation();
    const [role, setRole] = useState<Role | null>(null);

    return (
        <>
            <Head title={t('roles.choose.head')} />
            <div className="flex flex-col gap-6">
                <Form
                    {...RoleController.store.form()}
                    disableWhileProcessing
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <fieldset className="grid gap-3">
                                <legend className="sr-only">
                                    {t('roles.choose.legend')}
                                </legend>
                                {roles.map((item) => {
                                    const Icon = roleIcons[item];

                                    return (
                                        <label
                                            key={item}
                                            className={cn(
                                                'flex cursor-pointer items-start gap-3 rounded-xl border bg-card p-4 text-left transition-colors hover:bg-accent/50',
                                                'has-checked:border-primary has-checked:ring-1 has-checked:ring-primary has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50',
                                            )}
                                            data-test={`role-${item}`}
                                        >
                                            <input
                                                type="radio"
                                                name="role"
                                                value={item}
                                                required
                                                checked={role === item}
                                                onChange={() => setRole(item)}
                                                className="sr-only"
                                            />
                                            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-foreground">
                                                <Icon
                                                    aria-hidden
                                                    className="size-5"
                                                />
                                            </span>
                                            <span className="grid min-w-0 gap-0.5">
                                                <span className="font-medium">
                                                    {t(`roles.${item}.title`)}
                                                </span>
                                                <span className="text-sm text-muted-foreground">
                                                    {t(
                                                        `roles.${item}.description`,
                                                    )}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                                <InputError message={errors.role} />
                            </fieldset>

                            {role !== null && (
                                <div className="grid gap-2">
                                    <div className="flex items-start gap-3">
                                        {/* A new role, a new tick: one role's sentence never carries over. */}
                                        <Checkbox
                                            key={role}
                                            id="agreement"
                                            name="agreement"
                                            value="1"
                                            required
                                            className="mt-0.5"
                                            aria-invalid={
                                                errors.agreement
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <Label
                                            htmlFor="agreement"
                                            className="leading-snug font-normal"
                                        >
                                            {t(`roles.agreement.${role}`)}
                                        </Label>
                                    </div>
                                    <InputError message={errors.agreement} />
                                </div>
                            )}

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing || role === null}
                                data-test="choose-role-button"
                            >
                                {processing && <Spinner />}
                                {t('common.continue')}
                            </Button>
                        </>
                    )}
                </Form>

                <TextLink
                    href={logout()}
                    as="button"
                    className="mx-auto block cursor-pointer text-sm"
                >
                    {t('nav.log_out')}
                </TextLink>
            </div>
        </>
    );
}

ChooseRole.layout = {
    title: 'roles.choose.title',
    description: 'roles.choose.description',
};
