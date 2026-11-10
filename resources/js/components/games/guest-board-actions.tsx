import { MonitorX, RefreshCw } from 'lucide-react';
import { useState } from 'react';
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

export type GuestBoardActionsProps = {
    /** The teacher whose phone claimed the board (null: unknown). */
    teacherName: string | null;
    busy: boolean;
    failed: boolean;
    onNotMine: () => void;
    onClose: () => void;
};

/**
 * A claimed guest board says whose it is (docs/architecture.md §4, step 5):
 * the code was on the wall for the whole room, so another teacher could
 * have claimed it first — «Mənim deyil — yeni kod» undoes that with one
 * tap. «Lövhəni bağla» closes it: the bearer stops working and the tab
 * keeps nothing.
 */
export function GuestBoardActions({
    teacherName,
    busy,
    failed,
    onNotMine,
    onClose,
}: GuestBoardActionsProps) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col items-center gap-3 text-center">
            {teacherName !== null && (
                <p className="text-xl text-neutral-700 max-lg:text-base">
                    {t('lovhe.claimed_by', { name: teacherName })}
                </p>
            )}
            <div className="flex flex-wrap justify-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="lg"
                    className="max-lg:h-11"
                    disabled={busy}
                    onClick={onNotMine}
                >
                    <RefreshCw />
                    {t('lovhe.not_mine')}
                </Button>
                <CloseBoard busy={busy} onClose={onClose} />
            </div>
            {failed && (
                <p className="text-base text-red-700" role="alert">
                    {t('lovhe.close_failed')}
                </p>
            )}
        </div>
    );
}

function CloseBoard({ busy, onClose }: { busy: boolean; onClose: () => void }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="lg"
                    className="max-lg:h-11"
                    disabled={busy}
                >
                    {busy ? <Spinner /> : <MonitorX />}
                    {t('lovhe.close')}
                </Button>
            </DialogTrigger>
            <DialogContent showCloseButton={false}>
                <DialogHeader>
                    <DialogTitle>{t('lovhe.close_confirm.title')}</DialogTitle>
                    <DialogDescription>
                        {t('lovhe.close_confirm.description')}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="outline"
                            className="max-lg:h-11"
                        >
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        className="max-lg:h-11"
                        onClick={() => {
                            setOpen(false);
                            onClose();
                        }}
                    >
                        {t('lovhe.close')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
