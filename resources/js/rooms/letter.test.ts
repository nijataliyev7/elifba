import { describe, expect, it, vi } from 'vite-plus/test';
import { eventId } from './answer';
import { RoomLetter } from './contract';
import type { Reply } from './http';
import { LETTER_RETRY_MS, sendLetter } from './letter';

/*
 * A bought letter (ADR 037 decision 12): one event id per tap, sent
 * unchanged on every retry, naming the next place and never a letter; the
 * receipt is passed on as the server gave it, and the tiles come with the
 * state the page asks for next.
 */
const letter = RoomLetter.parse({
    event_id: eventId(1_795_534_226_900),
    round_id: '01kaqs0n2p3q4r5s6t7v8w9xyz',
    position: 1,
});

function replies(...list: (Reply | Error)[]) {
    const sent: unknown[] = [];
    const post = vi.fn(async (body: unknown) => {
        sent.push(structuredClone(body));
        const next = list.shift();

        if (next === undefined || next instanceof Error) {
            throw next ?? new Error('offline');
        }

        return next;
    });

    return { post, sent };
}

const noWait = vi.fn(async () => {});

describe('sendLetter', () => {
    it('passes on every receipt as the server gave it', async () => {
        for (const status of ['accepted', 'duplicate', 'bought', 'late']) {
            const { post } = replies({ status: 200, body: { status } });

            await expect(
                sendLetter({ letter, post, wait: noWait }),
            ).resolves.toEqual({ kind: 'receipt', receipt: { status } });
        }
    });

    it('sends the same body again, the same event id and place, until a receipt comes', async () => {
        const { post, sent } = replies(new Error('offline'), {
            status: 200,
            body: { status: 'accepted' },
        });

        await expect(
            sendLetter({ letter, post, wait: noWait }),
        ).resolves.toEqual({
            kind: 'receipt',
            receipt: { status: 'accepted' },
        });
        expect(sent).toEqual([letter, letter]);
        expect(Object.keys(letter)).toEqual([
            'event_id',
            'round_id',
            'position',
        ]);
    });

    it('stops when the round is no longer taking answers, and after its retries', async () => {
        const stopped = replies(new Error('offline'));

        await expect(
            sendLetter({
                letter,
                post: stopped.post,
                wait: noWait,
                keepTrying: () => false,
            }),
        ).resolves.toEqual({ kind: 'failed' });
        expect(stopped.sent).toHaveLength(1);

        const down = replies();

        await expect(
            sendLetter({ letter, post: down.post, wait: noWait }),
        ).resolves.toEqual({ kind: 'failed' });
        expect(down.sent).toHaveLength(LETTER_RETRY_MS.length + 1);
    });

    it('reads a refusal and a gone room as such', async () => {
        await expect(
            sendLetter({
                letter,
                post: replies({ status: 422, body: {} }).post,
                wait: noWait,
            }),
        ).resolves.toEqual({ kind: 'refused' });
        await expect(
            sendLetter({
                letter,
                post: replies({ status: 403, body: {} }).post,
                wait: noWait,
            }),
        ).resolves.toEqual({ kind: 'gone' });
    });
});
