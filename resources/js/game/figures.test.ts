import { describe, expect, it } from 'vite-plus/test';
import type { Figure, Pictures } from './figures';
import { chooseFigures, figureKey } from './figures';

const url = (name: string, version = 4) =>
    `https://elifba.test/content/${name}.svg?v=${version}`;

/** An animals pack's word pictures, by content key. */
const OWN: Record<string, string> = {
    'word:pişik': url('openmoji/1F408'),
    'word:it': url('openmoji/1F415'),
    'word:inək': url('openmoji/1F404'),
    'word:ayı': url('openmoji/1F43B'),
    'word:fil': url('openmoji/1F418'),
    'word:dəvə': url('openmoji/1F42A'),
    'word:quş': url('openmoji/1F426'),
    'word:dəvəquşu': url('animals/devequsu'),
};

/**
 * What `BoardProps` sends: each word's own picture, and the words that
 * begin with a letter or a syllable; nothing for «ə», «o» or «ma».
 */
const PICTURES: Pictures = {
    ...Object.fromEntries(
        Object.entries(OWN).map(([key, picture]) => [key, [picture]]),
    ),
    'letter:a': [OWN['word:ayı']],
    'letter:d': [OWN['word:dəvə'], OWN['word:dəvəquşu']],
    'letter:f': [OWN['word:fil']],
    'letter:i': [OWN['word:it'], OWN['word:inək']],
    'letter:p': [OWN['word:pişik']],
    'letter:q': [OWN['word:quş']],
    'syllable:a': [OWN['word:ayı']],
    'syllable:də': [OWN['word:dəvə'], OWN['word:dəvəquşu']],
    'syllable:fil': [OWN['word:fil']],
    'syllable:i': [OWN['word:inək']],
    'syllable:it': [OWN['word:it']],
    'syllable:pi': [OWN['word:pişik']],
    'syllable:quş': [OWN['word:quş']],
};

/** Its figures: pictures of its words, and two dinosaurs of no word. */
const FIGURES: Figure[] = [
    { url: url('openmoji/1F995'), words: [] },
    { url: url('openmoji/1F996'), words: [] },
    { url: url('openmoji/1F43B'), words: ['word:ayı'] },
    { url: url('openmoji/1F404'), words: ['word:inək'] },
    { url: url('openmoji/1F418'), words: ['word:fil'] },
    { url: url('openmoji/1F42A'), words: ['word:dəvə'] },
    { url: url('openmoji/1F426'), words: ['word:quş'] },
    { url: url('openmoji/1F427'), words: ['word:pinqvin'] },
    { url: url('openmoji/1F422'), words: ['word:tısbağa'] },
    { url: url('openmoji/1F407'), words: ['word:dovşan'] },
];

function round(id: string, keys: string[], image: string | null = null) {
    return {
        id,
        items: keys.map((key, index) => ({
            id: String.fromCharCode(97 + index),
            content_key: key,
        })),
        prompt: { image },
    };
}

const ids = (n: number, prefix = '01JROUND') =>
    Array.from(
        { length: n },
        (_, k) => `${prefix}${String(k).padStart(26 - prefix.length, '0')}`,
    );

describe('chooseFigures', () => {
    it('stands a word of the pack in front of its own picture', () => {
        const chosen = chooseFigures(
            round('01JROUND0000000000000000A1', [
                'word:dəvəquşu',
                'word:inək',
                'word:it',
                'word:pişik',
            ]),
            FIGURES,
            PICTURES,
        );

        expect(Object.fromEntries(chosen)).toEqual({
            a: OWN['word:dəvəquşu'],
            b: OWN['word:inək'],
            c: OWN['word:it'],
            d: OWN['word:pişik'],
        });
    });

    it('stands a letter in front of a word that begins with it, or alone', () => {
        for (const id of ids(100, '01JROUNDLETTER')) {
            const chosen = chooseFigures(
                round(id, ['letter:i', 'letter:d', 'letter:ə', 'letter:p']),
                FIGURES,
                PICTURES,
            );

            expect(PICTURES['letter:i']).toContain(chosen.get('a'));
            expect(PICTURES['letter:d']).toContain(chosen.get('b'));
            // No animal of the pack begins with «ə»: no wrong one stands behind it.
            expect(chosen.get('c')).toBeNull();
            expect(chosen.get('d')).toBe(OWN['word:pişik']);
        }

        const dogOrCow = new Set(
            ids(40, '01JROUNDLETTER').map((id) =>
                chooseFigures(round(id, ['letter:i']), FIGURES, PICTURES).get(
                    'a',
                ),
            ),
        );

        // Both words of «i» take turns, round by round.
        expect(dogOrCow).toEqual(new Set(PICTURES['letter:i']));
    });

    it('stands a syllable in front of a word it begins, or alone', () => {
        for (const id of ids(100, '01JROUNDSYLLABLE')) {
            const chosen = chooseFigures(
                round(id, ['syllable:də', 'syllable:pi', 'syllable:ma']),
                FIGURES,
                PICTURES,
            );

            expect(PICTURES['syllable:də']).toContain(chosen.get('a'));
            expect(chosen.get('b')).toBe(OWN['word:pişik']);
            // No word of the pack begins with «ma»: it stands alone.
            expect(chosen.get('c')).toBeNull();
        }
    });

    it('never gives a build_word tile the question’s picture, whatever host or version wrote it', () => {
        // Building «inək» (i-nək) with the decoy «pi»: the cow is the
        // question, so «i» stands alone instead of in front of the answer.
        const cow = 'http://192.168.1.20:8000/content/openmoji/1F404.svg?v=3';

        for (const id of ids(100, '01JROUNDWORD')) {
            const chosen = chooseFigures(
                round(id, ['syllable:i', 'syllable:nək', 'syllable:pi'], cow),
                FIGURES,
                PICTURES,
            );

            expect(Object.fromEntries(chosen)).toEqual({
                a: null,
                b: null,
                c: OWN['word:pişik'],
            });
        }
    });

    it('gives a word without a picture of its own a figure of none of the round’s words, none twice', () => {
        for (const id of ids(200)) {
            const chosen = chooseFigures(
                round(id, [
                    'word:filin',
                    'word:quşlar',
                    'word:oxuyur',
                    'word:inək',
                    'word:dəvəquşu',
                ]),
                FIGURES,
                PICTURES,
            );
            const others = [chosen.get('a'), chosen.get('b'), chosen.get('c')];

            expect(chosen.get('d')).toBe(OWN['word:inək']);
            expect(chosen.get('e')).toBe(OWN['word:dəvəquşu']);

            // No elephant behind «filin», no bird behind «quşlar», no camel
            // next to «dəvəquşu», no second cow.
            for (const word of [
                'word:fil',
                'word:quş',
                'word:dəvə',
                'word:inək',
            ]) {
                expect(others).not.toContain(OWN[word]);
            }

            expect(others).not.toContain(null);
            expect(new Set(chosen.values()).size).toBe(5);
        }
    });

    it('lets a figure stand behind a word that only shares its first letters', () => {
        const bird = FIGURES.filter((figure) =>
            figure.words.includes('word:quş'),
        );

        // «quyu» begins with «qu», not with «quş».
        expect([
            ...chooseFigures(
                round('01JROUNDSHARE0000000000002', ['word:quyu']),
                bird,
                PICTURES,
            ).values(),
        ]).toEqual([bird[0].url]);
    });

    it('shows the same figures for the same round, and others for another', () => {
        const keys = ['word:süd', 'word:içir', 'word:ot', 'word:yeyir'];
        const first = chooseFigures(
            round('01JROUND0000000000000000A1', keys),
            FIGURES,
        );
        const again = chooseFigures(
            round('01JROUND0000000000000000A1', keys),
            FIGURES,
        );
        const orders = new Set(
            ids(20).map((id) =>
                [...chooseFigures(round(id, keys), FIGURES).values()].join(),
            ),
        );

        expect([...again.entries()]).toEqual([...first.entries()]);
        expect(new Set(first.values()).size).toBe(4);
        expect(orders.size).toBeGreaterThan(10);
    });

    it('repeats figures only once every other one is used', () => {
        const chosen = [
            ...chooseFigures(
                round('01JROUNDFEW000000000000001', [
                    'word:bal',
                    'word:bu',
                    'word:bir',
                    'word:və',
                    'word:ev',
                ]),
                FIGURES.slice(0, 3),
            ).values(),
        ];

        expect(new Set(chosen.slice(0, 3)).size).toBe(3);
        expect(chosen.slice(3)).toEqual(chosen.slice(0, 2));
    });

    it('draws no figure when every one shows a word of the round', () => {
        // «ayılar» has no picture of its own and begins with «ayı»: not the bear.
        expect([
            ...chooseFigures(
                round('01JROUNDNONE00000000000001', ['word:ayılar']),
                FIGURES.slice(2, 3),
                PICTURES,
            ).values(),
        ]).toEqual([null]);
        expect([
            ...chooseFigures(
                round('01JROUNDNONE00000000000002', ['word:süd']),
                [],
            ).values(),
        ]).toEqual([null]);
    });

    it('names the texture a picture is loaded under', () => {
        expect(figureKey(url('openmoji/1F995'))).toBe(
            `figure:${url('openmoji/1F995')}`,
        );
    });
});
