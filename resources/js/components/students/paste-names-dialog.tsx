import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import { PasteNames } from '@/components/students/paste-names';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import type { PasteLimits } from '@/types';

type Props = {
    classId: string;
    limits: PasteLimits;
    /** Opens the dialog from a larger button (the empty class). */
    size?: 'default' | 'lg';
    /** `outline` beside a page's main action. */
    variant?: 'default' | 'outline';
};

/**
 * «Şagird əlavə et» on the class page (docs/architecture.md §7): the paste
 * flow (`PasteNames`) in a dialog, which closes once the names are added.
 * The flow lives inside the dialog's content, so closing it drops the
 * pasted text.
 */
export function PasteNamesDialog({
    classId,
    limits,
    size = 'default',
    variant = 'default',
}: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size={size} variant={variant} data-test="paste-names">
                    <UserPlus />
                    {t('students.paste.button')}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
                <PasteNames
                    classId={classId}
                    limits={limits}
                    onAdded={() => setOpen(false)}
                    heading={({ title, description }) => (
                        <DialogHeader>
                            <DialogTitle>{title}</DialogTitle>
                            <DialogDescription>{description}</DialogDescription>
                        </DialogHeader>
                    )}
                />
            </DialogContent>
        </Dialog>
    );
}
