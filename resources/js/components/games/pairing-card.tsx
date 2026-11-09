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
                'relative flex items-center gap-6 rounded-3xl border-2 border-neutral-200 bg-white text-neutral-900 shadow-lg',
                compact ? 'p-4' : 'p-6',
            )}
            aria-labelledby="pairing-title"
        >
            {onClose !== undefined && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="absolute top-2 right-2"
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
                )}
            >
                {pairing === null ? (
                    <Spinner className="size-8 text-neutral-400" />
                ) : (
                    <QRCodeSVG
                        value={pairing.url}
                        size={compact ? 160 : 224}
                        level="M"
                        marginSize={0}
                        title={t('games.board.pairing.qr_label')}
                    />
                )}
            </div>

            <div className="flex max-w-sm min-w-0 flex-col gap-2 pr-8">
                <h2
                    id="pairing-title"
                    className={cn(
                        'font-bold',
                        compact ? 'text-xl' : 'text-3xl',
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
                            )}
                        >
                            {t('games.board.pairing.scan', {
                                url: withoutScheme(pairing.entry_url),
                            })}
                        </p>
                        <p
                            className={cn(
                                'font-bold tracking-widest tabular-nums',
                                compact ? 'text-4xl' : 'text-6xl',
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
