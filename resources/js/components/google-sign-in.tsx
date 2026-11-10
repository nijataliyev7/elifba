import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import InputError from '@/components/input-error';

/**
 * Google's own «G», unchanged, only to label its sign-in button
 * (architecture §8 "Brand marks"). Drawn inline: nothing is loaded from Google.
 */
export function GoogleMark(props: ComponentProps<'svg'>) {
    return (
        <svg viewBox="0 0 48 48" aria-hidden="true" {...props}>
            <path
                fill="#EA4335"
                d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"
            />
            <path
                fill="#4285F4"
                d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"
            />
            <path
                fill="#FBBC05"
                d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"
            />
            <path
                fill="#34A853"
                d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"
            />
        </svg>
    );
}

type Props = {
    /** Our own Google route (Wayfinder): the browser leaves for Google from there. */
    href: string;
    label: string;
};

/**
 * «Google ilə daxil ol» and its siblings (architecture §7 "Google sign-in"):
 * a plain link, not an Inertia visit, because the answer is a redirect to
 * Google. Drawn to Google's sign-in branding guidelines: the light theme
 * (white, a 1px #747775 border, #1F1F1F text) and in dark mode the dark one
 * (#131314, #8E918F, #E3E3E3) with the «G» on its white circle, 12px either
 * side and about 10px between the «G» and the text. Shows why the last round trip
 * failed, from the `google` error.
 */
export default function GoogleSignIn({ href, label }: Props) {
    const { errors } = usePage().props;

    return (
        <div className="grid gap-2">
            <a
                href={href}
                data-test="google-button"
                className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-md border border-[#747775] bg-white px-3 text-sm font-medium text-[#1F1F1F] transition-colors outline-none hover:bg-[#F2F2F2] focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:border-[#8E918F] dark:bg-[#131314] dark:text-[#E3E3E3] dark:hover:bg-[#1F1F20]"
            >
                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-white">
                    <GoogleMark className="size-[18px]" />
                </span>
                {label}
            </a>

            <InputError
                message={errors.google}
                role="alert"
                className="text-center"
            />
        </div>
    );
}
