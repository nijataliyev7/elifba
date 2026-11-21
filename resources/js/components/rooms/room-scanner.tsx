import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { store as joinRoom } from '@/routes/rooms/join';
import { scannedCode, scanOrigins } from '@/rooms/scan';

/** The texts of the QR codes a frame holds. */
type Decode = (video: HTMLVideoElement) => Promise<string[]>;

type DetectorClass = {
    new (options: { formats: string[] }): {
        detect: (source: HTMLVideoElement) => Promise<{ rawValue: string }[]>;
    };
    getSupportedFormats: () => Promise<string[]>;
};

function detectorClass(): DetectorClass | undefined {
    return (window as unknown as { BarcodeDetector?: DetectorClass })
        .BarcodeDetector;
}

/**
 * Whether this browser can scan a code here (ADR 026 decision 15): a
 * camera it may open — `getUserMedia` in a secure context. The decoder is
 * always on the device: the browser's own `BarcodeDetector`, else the
 * bundled one (`loadDecoder()`). Without a camera the button is not shown
 * and the code is typed.
 */
export function canScan(): boolean {
    return (
        window.isSecureContext &&
        typeof navigator.mediaDevices?.getUserMedia === 'function'
    );
}

/** The browser's own decoder when it reads QR codes (Chrome, Edge, Android). */
async function nativeDecoder(): Promise<Decode | null> {
    const Detector = detectorClass();

    if (Detector === undefined) {
        return null;
    }

    const formats = await Detector.getSupportedFormats().catch(
        (): string[] => [],
    );

    if (!formats.includes('qr_code')) {
        return null;
    }

    const detector = new Detector({ formats: ['qr_code'] });

    return async (video) =>
        (await detector.detect(video)).map((code) => code.rawValue);
}

/** The longest side a frame is read at by the bundled decoder: enough for a QR in the frame, light on a phone. */
const FRAME_PX = 640;

/**
 * The bundled decoder (`jsqr`, Apache-2.0) for a browser without one of
 * its own (Safari): loaded as its own chunk from the app's host when the
 * scanner first needs it. Each frame is drawn on a canvas that never
 * leaves the page and read there.
 */
async function bundledDecoder(): Promise<Decode> {
    const { default: jsQR } = await import('jsqr');
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    return async (video) => {
        if (context === null || video.videoWidth === 0) {
            return [];
        }

        const scale = Math.min(
            1,
            FRAME_PX / Math.max(video.videoWidth, video.videoHeight),
        );
        const width = Math.round(video.videoWidth * scale);
        const height = Math.round(video.videoHeight * scale);

        if (canvas.width !== width || canvas.height !== height) {
            canvas.width = width;
            canvas.height = height;
        }

        context.drawImage(video, 0, 0, width, height);
        const found = jsQR(
            context.getImageData(0, 0, width, height).data,
            width,
            height,
            { inversionAttempts: 'dontInvert' },
        );

        return found === null ? [] : [found.data];
    };
}

/** The device's own decoder, else the bundled one; null when neither could be had. */
async function loadDecoder(): Promise<Decode | null> {
    return (
        (await nativeDecoder()) ?? (await bundledDecoder().catch(() => null))
    );
}

/** How often a frame is looked at, in ms. */
const LOOK_MS = 200;

/**
 * The scanner (`RoomScanner`, docs/frontend.md rule 15 "Games first"; ADR
 * 026 decision 15): opened by the code field's camera button on «Oyunlar»
 * and at `/oyun`. A dialog, full screen below `sm` and black in both
 * themes, with the camera's picture in a square with four corner marks.
 * The picture is decoded on the device and goes nowhere: no frame,
 * picture or decoded text is sent, kept or logged, and it makes no sound.
 * A code of this app (`scannedCode()`) closes it and posts `rooms.join.store`
 * with the six digits — joining with that scan (ADR 033 decision 21 as
 * amended 2026-11-21) — never the address it read; any other QR says so
 * under the frame while it keeps looking; a refused camera asks for the
 * code to be typed. The camera stops when the dialog closes, when a code
 * is read and while the page is hidden.
 */
export function RoomScanner({
    open,
    onOpenChange,
    entrance,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The entrance's address (`APP_URL/oyun`): its origin is this app's too. */
    entrance: string | null;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                showCloseButton={false}
                className="inset-0 flex h-dvh max-h-dvh w-full max-w-none translate-x-0 translate-y-0 flex-col gap-0 rounded-none border-0 bg-black p-0 text-white data-[state=closed]:zoom-out-100 data-[state=open]:zoom-in-100 motion-reduce:animate-none sm:inset-auto sm:top-[50%] sm:left-[50%] sm:h-auto sm:max-w-md sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-2xl"
            >
                <div className="flex items-center gap-3 px-4 pt-[max(env(safe-area-inset-top),0.5rem)] pb-2">
                    <DialogTitle className="min-w-0 flex-1 text-lg text-white">
                        {t('rooms.scan.title')}
                    </DialogTitle>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            className="size-11 shrink-0 rounded-full text-white hover:bg-white/10 hover:text-white"
                            aria-label={t('common.close')}
                        >
                            <X className="size-5" />
                        </Button>
                    </DialogClose>
                </div>
                <ScannerView
                    entrance={entrance}
                    onCode={(code) => {
                        onOpenChange(false);
                        // The scan is the tap: it joins at once (ADR 033 decision 21 as amended 2026-11-21).
                        router.post(joinRoom.url(code));
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}

/** The camera while the scanner is open: mounted with the dialog's content, stopped with it. */
function ScannerView({
    entrance,
    onCode,
}: {
    entrance: string | null;
    onCode: (code: string) => void;
}) {
    const { t } = useTranslation();
    const video = useRef<HTMLVideoElement>(null);
    const found = useRef(onCode);
    const [status, setStatus] = useState<'looking' | 'foreign' | 'denied'>(
        'looking',
    );

    useEffect(() => {
        found.current = onCode;
    });

    useEffect(() => {
        const origins = scanOrigins(window.location.origin, entrance);
        let stream: MediaStream | null = null;
        let decode: Decode | null = null;
        let timer = 0;
        let closed = false;

        const stop = () => {
            window.clearTimeout(timer);
            stream?.getTracks().forEach((track) => track.stop());
            stream = null;

            if (video.current !== null) {
                video.current.srcObject = null;
            }
        };

        const look = () => {
            timer = window.setTimeout(() => {
                const element = video.current;

                if (
                    closed ||
                    stream === null ||
                    element === null ||
                    decode === null
                ) {
                    return;
                }

                void decode(element)
                    .then((texts) => {
                        if (closed || stream === null) {
                            return;
                        }

                        for (const text of texts) {
                            const code = scannedCode(text, origins);

                            if (code !== null) {
                                stop();
                                found.current(code);

                                return;
                            }
                        }

                        if (texts.length > 0) {
                            setStatus('foreign');
                        }

                        look();
                    })
                    // A frame that could not be read: the next one may be.
                    .catch(() => look());
            }, LOOK_MS);
        };

        const start = async () => {
            try {
                decode ??= await loadDecoder();

                if (decode === null) {
                    setStatus('denied');

                    return;
                }

                const opened = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false,
                });

                if (closed || document.hidden || video.current === null) {
                    opened.getTracks().forEach((track) => track.stop());

                    return;
                }

                stream = opened;
                video.current.srcObject = opened;
                await video.current.play().catch(() => null);
                look();
            } catch {
                if (!closed) {
                    setStatus('denied');
                }
            }
        };

        const shown = () => {
            if (document.hidden) {
                stop();
            } else if (stream === null && !closed) {
                void start();
            }
        };

        document.addEventListener('visibilitychange', shown);
        void start();

        return () => {
            closed = true;
            document.removeEventListener('visibilitychange', shown);
            stop();
        };
    }, [entrance]);

    return (
        <div className="flex flex-1 flex-col items-center gap-4 overflow-y-auto px-6 pt-2 pb-[max(env(safe-area-inset-bottom),1.5rem)] sm:flex-none">
            <div className="relative aspect-square w-full max-w-[min(80vw,22rem)] overflow-hidden rounded-2xl bg-white/5">
                <video
                    ref={video}
                    muted
                    playsInline
                    autoPlay
                    aria-hidden
                    className="size-full object-cover"
                />
                {(
                    [
                        'top-3 left-3 border-t-4 border-l-4 rounded-tl-xl',
                        'top-3 right-3 border-t-4 border-r-4 rounded-tr-xl',
                        'bottom-3 left-3 border-b-4 border-l-4 rounded-bl-xl',
                        'bottom-3 right-3 border-b-4 border-r-4 rounded-br-xl',
                    ] as const
                ).map((corner) => (
                    <span
                        key={corner}
                        aria-hidden
                        className={cn('absolute size-10 border-white', corner)}
                    />
                ))}
            </div>
            <p
                role="status"
                className={cn(
                    'min-h-5 text-center text-sm font-medium',
                    status === 'looking' ? 'sr-only' : 'text-amber-300',
                )}
            >
                {status === 'foreign' && t('rooms.scan.foreign')}
                {status === 'denied' && t('rooms.scan.denied')}
            </p>
            <DialogDescription className="max-w-sm text-center text-base text-white">
                {t('rooms.scan.line')}
            </DialogDescription>
            <p className="max-w-sm text-center text-sm text-white/60">
                {t('rooms.scan.privacy')}
            </p>
        </div>
    );
}
