import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

export type Translate = (
    key: string,
    replacements?: Record<string, string | number>,
) => string;

/**
 * Picks the plural form for `count` from "{one}…|{other}…", using the
 * language's own rules. Azerbaijani keeps the noun singular after a number,
 * so its forms are usually equal.
 */
function pluralize(value: string, locale: string, count: number): string {
    const forms = new Map<string, string>();

    for (const form of value.split('|')) {
        const match = /^\{(zero|one|two|few|many|other)\}(.*)$/s.exec(form);

        if (match) {
            forms.set(match[1], match[2]);
        }
    }

    if (forms.size === 0) {
        return value;
    }

    const category = new Intl.PluralRules(locale).select(count);

    return forms.get(category) ?? forms.get('other') ?? value;
}

/**
 * Interface strings from `lang/{locale}.json`, shared by the server as the
 * `translations` prop. Keys are dotted (`auth.login.title`); placeholders use
 * Laravel's `:name` syntax; a numeric `count` replacement also selects the
 * plural form. An unknown key comes back unchanged, and the parity test
 * keeps az.json and en.json in step.
 */
export function useTranslation(): { t: Translate; locale: string } {
    const { translations, locale } = usePage().props;

    const t = useCallback<Translate>(
        (key, replacements = {}) => {
            const raw = translations[key] ?? key;
            const value =
                typeof replacements.count === 'number' && raw.includes('|')
                    ? pluralize(raw, locale, replacements.count)
                    : raw;

            // Whole-name matching, so `:to` never eats the start of `:total`.
            return value.replace(/:([a-z_]+)/g, (placeholder, name: string) =>
                name in replacements ? String(replacements[name]) : placeholder,
            );
        },
        [translations, locale],
    );

    return { t, locale };
}
