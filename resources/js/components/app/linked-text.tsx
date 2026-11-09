import type { AnchorHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type Props = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'children'> & {
    /** A translated sentence whose [bracketed] words become the link. */
    text: string;
};

/**
 * Renders one translated sentence with a link inside it, so the whole
 * sentence stays one key and each language puts the link where its word
 * order needs it (docs/frontend.md §2): "I have read the [privacy note]…".
 */
export function LinkedText({ text, className, ...anchor }: Props) {
    const match = /^(.*?)\[(.+?)\](.*)$/s.exec(text);

    if (!match) {
        return <>{text}</>;
    }

    return (
        <>
            {match[1]}
            <a
                {...anchor}
                className={cn(
                    'text-foreground underline decoration-neutral-300 underline-offset-4 hover:decoration-current dark:decoration-neutral-500',
                    className,
                )}
            >
                {match[2]}
            </a>
            {match[3]}
        </>
    );
}
