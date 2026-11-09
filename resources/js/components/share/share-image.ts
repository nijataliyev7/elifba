import { domToBlob } from 'modern-screenshot';
import { CARD_HEIGHT, CARD_WIDTH, fitShareCard } from './share-card';

/*
 * The share card as a PNG file, made in the browser (architecture §6), and
 * the one tap that hands it on: the Web Share sheet with the file, or a
 * download where sharing files is not supported.
 */

/** The faces the card uses: Andika, regular and bold. */
const FACES = ['400 48px Andika', '700 48px Andika'];

/**
 * Captures the card node (already in the page, off-screen) as a 1080×1920
 * PNG. Waits for the card's fonts and pictures first, so the picture never
 * shows a fallback font or an empty avatar, and fits the places to the
 * fonts' real metrics. Fonts and pictures are files on the page's own
 * origin; modern-screenshot inlines them, so nothing leaves the host.
 */
export async function makeShareImage(
    node: HTMLElement,
    fileName: string,
): Promise<File> {
    const text = node.textContent ?? '';

    // `load()` answers with the faces it matched: none when the page declares
    // no Andika at all, which `check()` alone would wave through.
    const loaded = await Promise.all(
        FACES.map((face) => document.fonts.load(face, text)),
    );
    await document.fonts.ready;

    if (
        loaded.some((faces) => faces.length === 0) ||
        !FACES.every((face) => document.fonts.check(face, text))
    ) {
        throw new Error('The share card font did not load.');
    }

    await Promise.all(
        Array.from(node.querySelectorAll('img'), (image) =>
            // A picture that fails is left empty rather than blocking the card.
            image.decode().catch(() => undefined),
        ),
    );

    fitShareCard(node);

    const blob = await domToBlob(node, {
        width: CARD_WIDTH,
        height: CARD_HEIGHT,
        scale: 1,
        type: 'image/png',
        font: { preferredFormat: 'woff2' },
    });

    return new File([blob], fileName, { type: 'image/png' });
}

/**
 * Whether this browser can hand the file to the Web Share sheet. Only in a
 * secure context (https), and not in most desktop browsers.
 */
export function canShareFile(file: File): boolean {
    return (
        typeof navigator.canShare === 'function' &&
        navigator.canShare({ files: [file] })
    );
}

/**
 * Runs inside the tap: `navigator.share` is called before anything is
 * awaited, or iOS drops the user activation and refuses. No `text` or
 * `title`: some WhatsApp versions drop the file when text comes with it.
 * Closing the sheet is a cancel, not an error; any other refusal falls back
 * to the download.
 */
export function shareOrDownload(file: File, url: string): void {
    if (!canShareFile(file)) {
        download(url, file.name);

        return;
    }

    navigator.share({ files: [file] }).catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        download(url, file.name);
    });
}

/**
 * Saves the PNG under its file name. `url` is the preview's object URL,
 * which the panel revokes when the picture is replaced or the page closes.
 */
function download(url: string, fileName: string): void {
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    link.rel = 'noopener';
    document.body.append(link);
    link.click();
    link.remove();
}
