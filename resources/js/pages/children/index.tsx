import { Head } from '@inertiajs/react';
import { ArrowUpDown, Check } from 'lucide-react';
import { useState } from 'react';
import { AddChildDialog } from '@/components/children/add-child-dialog';
import { ChildCard } from '@/components/children/child-card';
import { ChildOrder } from '@/components/children/child-order';
import { StudentAvatar } from '@/components/students/student-avatar';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/children';
import type { Avatar, ChildRow } from '@/types';

type Props = {
    /** The parent's children in their own order, never by stars. */
    children: ChildRow[];
    avatars: Avatar[];
    limits: { max: number };
};

/**
 * «Uşaqlarım», the parent area's home (architecture §1, §5 "A child's
 * stars", ADR 011), phone first (docs/frontend.md §1 "The parent shell"):
 * a card per child in the parent's own order with today's stars only and
 * «Oyna» on it, adding a child with a name and an avatar, and the order
 * changed on demand. No ranking, no total, nothing sorted by stars.
 */
export default function ChildrenIndex({ children, avatars, limits }: Props) {
    const { t } = useTranslation();
    const [ordering, setOrdering] = useState(false);
    const used = children.map((child) => child.avatar);
    const canAdd = children.length < limits.max;

    return (
        <>
            <Head title={t('children.index.title')} />

            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:gap-8 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('children.index.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('children.index.description')}
                        </p>
                    </div>
                    {children.length > 0 && canAdd && !ordering && (
                        <AddChildDialog avatars={avatars} used={used} />
                    )}
                </div>

                {children.length === 0 ? (
                    <div className="flex flex-col items-center gap-5 rounded-2xl border border-dashed p-8 text-center">
                        <span aria-hidden className="flex -space-x-3">
                            {avatars.slice(0, 3).map(({ key }) => (
                                <StudentAvatar
                                    key={key}
                                    avatar={key}
                                    avatars={avatars}
                                    className="size-16 rounded-full bg-background ring-4 ring-background"
                                />
                            ))}
                        </span>
                        <div className="space-y-1">
                            <p className="text-lg font-medium">
                                {t('children.index.empty_title')}
                            </p>
                            <p className="max-w-md text-sm text-muted-foreground">
                                {t('children.index.empty_description')}
                            </p>
                        </div>
                        <AddChildDialog
                            avatars={avatars}
                            used={used}
                            size="lg"
                        />
                    </div>
                ) : ordering ? (
                    <ChildOrder items={children} avatars={avatars} />
                ) : (
                    <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {children.map((child) => (
                            <li key={child.id}>
                                <ChildCard child={child} avatars={avatars} />
                            </li>
                        ))}
                    </ul>
                )}

                {children.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        {children.length > 1 ? (
                            <Button
                                variant={ordering ? 'default' : 'outline'}
                                className="h-11"
                                aria-pressed={ordering}
                                onClick={() => setOrdering(!ordering)}
                                data-test="order-children"
                            >
                                {ordering ? <Check /> : <ArrowUpDown />}
                                {ordering
                                    ? t('children.order.done')
                                    : t('children.order.button')}
                            </Button>
                        ) : (
                            <span />
                        )}
                        {!canAdd && (
                            <p className="text-sm text-muted-foreground">
                                {t('children.index.limit', {
                                    max: limits.max,
                                })}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

ChildrenIndex.layout = {
    breadcrumbs: [{ title: 'children.index.title', href: index() }],
};
