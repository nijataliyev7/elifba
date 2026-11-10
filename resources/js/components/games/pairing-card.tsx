import { QRCodeSVG } from 'qrcode.react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { Pairing } from '@/game/contract';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

type Props = {
    /** Null while the code is on its way. */
    pairing: Pairing | null;
    failed: boolean;
    /** Smaller, for a corner of the board during play. */
    compact?: boolean;
    /** Hides the card; a guest board's waiting screen has nothing else to show. */
    onClose?: () => void;
};

/**
 * The pairing card on the board (docs/architecture.md §4, "Pairing"): a
 * QR of `{APP_URL}/pult/{code}` and the code in large type, for the
 * teacher's phone — which is logged in as the teacher; the code only says
 * which board. A hello from the phone hides it; «Pultu qoş» brings it back.
 * Below `lg` it is smaller (a 144px QR, still read by a camera) and below
 * `sm` it stacks (docs/frontend.md §6).
 */
export function PairingCard({
    pairing,
    failed,
    compact = false,
    onClose,
}: Props) {
    const { t } = useTranslation();

    return (
        <section
            className={cn(
                'relative flex items-center gap-6 rounded-3xl border-2 border-neutral-200 bg-white text-neutral-900 shadow-lg max-lg:gap-4 max-lg:p-4 max-sm:flex-col max-sm:pt-12 max-sm:text-center',
                compact ? 'p-4' : 'p-6',
            )}
            aria-labelledby="pairing-title"
        >
            {onClose !== undefined && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="absolute top-2 right-2 max-lg:size-11"
                    onClick={onClose}
                    aria-label={t('common.close')}
                >
                    <X />
                </Button>
            )}

            <div
                className={cn(
                    'flex shrink-0 items-center justify-center rounded-xl bg-white',
                    compact ? 'size-40' : 'size-56',
                    'max-lg:size-36',
                )}
            >
                {pairing === null ? (
                    <Spinner className="size-8 text-neutral-400" />
                ) : (
                    <QRCodeSVG
                        value={pairing.url}
                        size={compact ? 160 : 224}
                        className="size-full"
                        level="M"
                        marginSize={0}
                        title={t('games.board.pairing.qr_label')}
                    />
                )}
            </div>

            <div className="flex max-w-sm min-w-0 flex-col gap-2 pr-8 max-lg:max-w-60 max-lg:pr-10 max-sm:max-w-none max-sm:pr-0">
                <h2
                    id="pairing-title"
                    className={cn(
                        'font-bold',
                        compact ? 'text-xl' : 'text-3xl',
                        'max-lg:text-xl',
                    )}
                >
                    {t('games.board.pairing.title')}
                </h2>
                {pairing === null ? (
                    <p className="text-lg text-neutral-600" role="status">
                        {failed
                            ? t('games.board.pairing.failed')
                            : t('games.board.pairing.loading')}
                    </p>
                ) : (
                    <>
                        <p
                            className={cn(
                                'text-neutral-600',
                                compact ? 'text-base' : 'text-lg',
                                'max-lg:text-sm',
                            )}
                        >
                            {t('games.board.pairing.scan', {
                                url: withoutScheme(pairing.entry_url),
                            })}
                        </p>
                        <p
                            className={cn(
                                'font-bold tracking-widest whitespace-nowrap tabular-nums',
                                compact ? 'text-4xl' : 'text-6xl',
                                'max-lg:text-4xl',
                            )}
                            aria-label={pairing.code.split('').join(' ')}
                        >
                            {pairing.code.slice(0, 3)} {pairing.code.slice(3)}
                        </p>
                    </>
                )}
            </div>
        </section>
    );
}

function withoutScheme(url: string): string {
    return url.replace(/^https?:\/\//, '');
}
