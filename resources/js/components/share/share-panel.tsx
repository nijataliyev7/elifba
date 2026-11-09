import { Download, RotateCcw, Share2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { ShareCard as ShareCardData } from '@/types';
import { ShareCard } from './share-card';
import { canShareFile, makeShareImage, shareOrDownload } from './share-image';

type Picture =
    | { status: 'preparing' }
    | { status: 'failed' }
    | { status: 'ready'; file: File; url: string; shareable: boolean };

/**
 * The share card on the podium page (architecture §6): the PNG is made as
 * soon as the page opens and shown as the preview, so «Paylaş» can hand it
 * to the share sheet inside the tap. The 1080×1920 card itself is rendered
 * off-screen (never `display: none`, which has no layout to capture). Key
 * the panel by the card's file name, so another day or week starts afresh.
 */
export function SharePanel({ card }: { card: ShareCardData }) {
    const { t } = useTranslation();
    const cardRef = useRef<HTMLDivElement>(null);
    const [attempt, setAttempt] = useState(0);
    const [picture, setPicture] = useState<Picture>({ status: 'preparing' });

    useEffect(() => {
        const node = cardRef.current;

        if (node === null) {
            return;
        }

        let cancelled = false;
        let url: string | null = null;

        makeShareImage(node, card.file_name).then(
            (file) => {
                if (cancelled) {
                    return;
                }

                url = URL.createObjectURL(file);
                setPicture({
                    status: 'ready',
                    file,
                    url,
                    shareable: canShareFile(file),
                });
            },
            () => {
                if (!cancelled) {
                    setPicture({ status: 'failed' });
                }
            },
        );

        return () => {
            cancelled = true;

            if (url !== null) {
                URL.revokeObjectURL(url);
            }
        };
    }, [card, attempt]);

    const retry = () => {
        setPicture({ status: 'preparing' });
        setAttempt((n) => n + 1);
    };

    return (
        <section
            aria-labelledby="share-title"
            className="flex flex-col gap-4 rounded-xl border p-4"
        >
            <div className="space-y-1">
                <h2 id="share-title" className="font-semibold">
                    {t('podium.share.title')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('podium.share.description')}
                </p>
            </div>

            <div className="mx-auto flex aspect-[9/16] w-full max-w-64 items-center justify-center overflow-hidden rounded-lg border bg-muted">
                {picture.status === 'ready' ? (
                    <img
                        src={picture.url}
                        alt={t('podium.share.preview')}
                        className="size-full object-contain"
                        data-test="share-preview"
                    />
                ) : picture.status === 'preparing' ? (
                    <span className="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground">
                        <Spinner />
                        {t('podium.share.preparing')}
                    </span>
                ) : (
                    <span className="flex flex-col items-center gap-3 p-4 text-center text-sm">
                        {t('podium.share.failed')}
                        <Button variant="outline" size="sm" onClick={retry}>
                            <RotateCcw />
                            {t('podium.share.retry')}
                        </Button>
                    </span>
                )}
            </div>

            <Button
                size="lg"
                disabled={picture.status !== 'ready'}
                data-test="share"
                onClick={() => {
                    if (picture.status === 'ready') {
                        shareOrDownload(picture.file, picture.url);
                    }
                }}
            >
                {picture.status === 'ready' && !picture.shareable ? (
                    <>
                        <Download />
                        {t('podium.share.download')}
                    </>
                ) : (
                    <>
                        <Share2 />
                        {t('podium.share.share')}
                    </>
                )}
            </Button>

            {picture.status === 'ready' && (
                <p className="text-sm text-muted-foreground">
                    {picture.shareable
                        ? t('podium.share.hint')
                        : t('podium.share.hint_download')}
                </p>
            )}

            {/* The card the PNG is made from, off-screen. */}
            <div
                aria-hidden
                inert
                className="pointer-events-none fixed top-0 -left-[20000px]"
            >
                <ShareCard ref={cardRef} card={card} />
            </div>
        </section>
    );
}
