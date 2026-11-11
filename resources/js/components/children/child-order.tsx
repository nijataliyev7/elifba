import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { StudentAvatar } from '@/components/students/student-avatar';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { order } from '@/routes/children';
import type { Avatar, ChildRow } from '@/types';

type Props = {
    /** The parent's children in their order. */
    items: ChildRow[];
    avatars: Avatar[];
};

/**
 * The parent's own order of their children (`PUT /children/order`,
 * `ReorderChildren`): up and down, saved at each move. The order is the
 * parent's, never the stars'.
 */
export function ChildOrder({ items, avatars }: Props) {
    const { t } = useTranslation();
    const [saving, setSaving] = useState(false);

    const move = (from: number, to: number): void => {
        const ids = items.map((child) => child.id);
        const [moved] = ids.splice(from, 1);
        ids.splice(to, 0, moved);

        router.put(
            order.url(),
            { child_ids: ids },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ??
                            t('common.something_went_wrong'),
                    ),
            },
        );
    };

    return (
        <ol className="divide-y rounded-2xl border" aria-busy={saving}>
            {items.map((child, position) => (
                <li
                    key={child.id}
                    className="flex min-w-0 items-center gap-3 py-2 pr-2 pl-3"
                >
                    <StudentAvatar
                        avatar={child.avatar}
                        avatars={avatars}
                        initial={child.display_name.charAt(0)}
                        className="size-11"
                    />
                    <span className="min-w-0 flex-1 truncate text-lg font-medium">
                        {child.display_name}
                    </span>
                    <div className="flex shrink-0 items-center gap-1">
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-11"
                            disabled={saving || position === 0}
                            onClick={() => move(position, position - 1)}
                            aria-label={t('children.move_up', {
                                name: child.display_name,
                            })}
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-11"
                            disabled={saving || position === items.length - 1}
                            onClick={() => move(position, position + 1)}
                            aria-label={t('children.move_down', {
                                name: child.display_name,
                            })}
                        >
                            <ArrowDown />
                        </Button>
                    </div>
                </li>
            ))}
        </ol>
    );
}
