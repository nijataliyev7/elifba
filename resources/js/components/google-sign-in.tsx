import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';

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
    /** The «or» line above the button, when something comes before it. */
    separator?: string;
    tabIndex?: number;
};

/**
 * «Google ilə daxil ol» and its siblings (architecture §7 "Google sign-in"):
 * a plain link, not an Inertia visit, because the answer is a redirect to
 * Google. Shows why the last round trip failed, from the `google` error.
 */
export default function GoogleSignIn({
    href,
    label,
    separator,
    tabIndex,
}: Props) {
    const { errors } = usePage().props;

    return (
        <div className="grid gap-2">
            {separator && (
                <div className="relative my-4">
                    <div className="absolute inset-0 flex items-center">
                        <Separator className="w-full" />
                    </div>
                    <div className="relative flex justify-center text-xs uppercase">
                        <span className="bg-background px-2 text-muted-foreground">
                            {separator}
                        </span>
                    </div>
                </div>
            )}

            <Button asChild variant="outline" className="w-full">
                <a href={href} tabIndex={tabIndex} data-test="google-button">
                    <GoogleMark className="size-4" />
                    {label}
                </a>
            </Button>

            <InputError
                message={errors.google}
                role="alert"
                className="text-center"
            />
        </div>
    );
}
