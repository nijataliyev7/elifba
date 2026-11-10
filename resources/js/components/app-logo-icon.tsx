import type { SVGAttributes } from 'react';

/**
 * Elifba's mark: a balloon from the board's «Tap!» game with «ə» cut out
 * (architecture §8 "Elifba's mark"). One colour, painted by the caller's
 * `fill-*` class; the master files are in `resources/images/brand/`.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            viewBox="0 0 64 64"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
            {...props}
        >
            <path
                fillRule="evenodd"
                d="M32 1.6C44 1.6 52.64 11.2 52.64 24.4C52.64 37.6 42.56 49.12 32 51.04C21.44 49.12 11.36 37.6 11.36 24.4C11.36 11.2 20 1.6 32 1.6ZM23.66 16.01A12 12 0 1 1 20.15 22.72L38.94 22.72A7.2 7.2 0 0 0 27 19.46ZM25.06 26.56L38.94 26.56A7.2 7.2 0 0 1 25.06 26.56Z"
            />
            <path d="M30.32 49.6L33.68 49.6L35.36 53.2Q32 54.16 28.64 53.2Z" />
            <path d="M31.04 53.44C35.84 56.8 27.2 59.68 32.48 63.52L34.28 63.52C29 59.68 37.64 56.8 32.84 53.44Z" />
        </svg>
    );
}
