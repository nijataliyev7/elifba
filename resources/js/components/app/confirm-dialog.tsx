import { Form } from '@inertiajs/react';
import type { RouteFormDefinition } from '@/wayfinder';
import { useState, type ComponentProps, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    /** The button that opens the dialog. */
    trigger: ReactNode;
    title: string;
    description: string;
    confirmLabel: string;
    /** The Wayfinder form the confirm button submits. */
    form: RouteFormDefinition<'get' | 'post'>;
    /** How the page answers; by default it reloads where it was. */
    options?: ComponentProps<typeof Form>['options'];
    /** Red confirm button for actions that cannot be undone. */
    destructive?: boolean;
};

/**
 * Asks before an action the teacher may regret (docs/frontend.md §4):
 * deleting, or archiving a whole class. Texts are already translated.
 */
export function ConfirmDialog({
    trigger,
    title,
    description,
    confirmLabel,
    form,
    options = { preserveScroll: true },
    destructive = false,
}: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <Form
                    {...form}
                    options={options}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant={
                                    destructive ? 'destructive' : 'default'
                                }
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {confirmLabel}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
