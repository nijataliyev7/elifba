import { Form } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import { AvatarPicker } from '@/components/children/avatar-picker';
import { ChildNameField } from '@/components/children/child-name-field';
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
import { update } from '@/routes/children';
import type { Avatar, Child } from '@/types';

type Props = {
    child: Child;
    avatars: Avatar[];
};

/** The child's first name and avatar, nothing else (ADR 013). */
export function EditChildDialog({ child, avatars }: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [avatar, setAvatar] = useState(child.avatar);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setAvatar(child.avatar);
            }}
        >
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="icon"
                    className="size-11 shrink-0"
                    aria-label={t('children.edit.button', {
                        name: child.display_name,
                    })}
                    data-test="edit-child"
                >
                    <Pencil />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90svh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{t('children.edit.title')}</DialogTitle>
                    <DialogDescription>
                        {t('children.edit.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...update.form(child.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <ChildNameField
                                id={`child-${child.id}-name`}
                                defaultValue={child.display_name}
                                error={errors.display_name}
                            />
                            <AvatarPicker
                                avatars={avatars}
                                value={avatar}
                                onChange={setAvatar}
                                error={errors.avatar}
                            />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        className="h-11"
                                    >
                                        {t('common.cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    className="h-11"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('common.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
