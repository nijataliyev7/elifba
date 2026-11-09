import type { SVGAttributes } from 'react';

/** Elifba's mark until step A5 brings the real artwork: the letter Ə. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <text
                x="50%"
                y="52%"
                dominantBaseline="middle"
                textAnchor="middle"
                fontSize="34"
                fontWeight="700"
                fontFamily="inherit"
            >
                Ə
            </text>
        </svg>
    );
}
