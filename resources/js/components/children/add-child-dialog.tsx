import { Form } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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
import { store } from '@/routes/children';
import type { Avatar } from '@/types';

type Props = {
    avatars: Avatar[];
    /** The avatars the parent's children already have: the first other one is offered. */
    used: string[];
    size?: 'default' | 'lg';
};

/**
 * «Uşaq əlavə et» (architecture §7 "Parents and their children", ADR 013):
 * a first name and an avatar, nothing else. The server opens the new
 * child's page.
 */
export function AddChildDialog({ avatars, used, size = 'default' }: Props) {
    const { t } = useTranslation();
    const offered =
        avatars.find(({ key }) => !used.includes(key))?.key ??
        avatars[0]?.key ??
        '';
    const [avatar, setAvatar] = useState(offered);

    return (
        <Dialog onOpenChange={() => setAvatar(offered)}>
            <DialogTrigger asChild>
                <Button
                    size={size}
                    className={size === 'lg' ? 'h-12 px-6 text-base' : ''}
                    data-test="add-child"
                >
                    <Plus />
                    {t('children.add.title')}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90svh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{t('children.add.title')}</DialogTitle>
                    <DialogDescription>
                        {t('children.add.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form {...store.form()} className="grid gap-6">
                    {({ processing, errors }) => (
                        <>
                            <ChildNameField
                                id="new-child-name"
                                error={errors.display_name}
                                autoFocus
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
                                    {t('children.add.button')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
