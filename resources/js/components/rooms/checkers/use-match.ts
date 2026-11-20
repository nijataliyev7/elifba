import { useCallback, useEffect, useRef, useState } from 'react';
import { timeLeft } from '@/components/rooms/checkers/match';
import type { RoomMatchState } from '@/components/rooms/checkers/match';
import matchRoutes from '@/routes/rooms/match';

/** How often, and how many times, a match read that failed or came back older than the room is tried again. */
const RETRY_MS = 1_000;
const RETRIES = 10;
/** A side's flag falls on the server at this moment; the page asks a little after it. */
const FLAG_GRACE_MS = 400;

/**
 * The match as `GET /rooms/{room}/match` serves it (ADR 034 decision 7),
 * read again when the room's revision rises, and when the side to move's
 * clock runs out — a loss on time is derived on the server, so no revision
 * tells of it. A read that fails, or answers older than the revision the
 * room state already showed, is tried again a few times, so one lost
 * request never leaves the board a move behind. `serverNow()` is the
 * server's clock as the page estimates it from the last read.
 */
export function useMatch({
    roomId,
    initial,
    revision,
    screen = false,
}: {
    roomId: string;
    initial: RoomMatchState | null;
    revision: number;
    /** The big screen asks with `?for=screen`: every taunt with its target. */
    screen?: boolean;
}): {
    view: RoomMatchState | null;
    reload: () => void;
    serverNow: () => number;
} {
    const [view, setView] = useState(initial);
    const shown = useRef(initial);
    const wanted = useRef(initial?.revision ?? 0);
    // The server's clock less the device's, from the last read.
    const offset = useRef(
        initial === null ? 0 : initial.server_ms - Date.now(),
    );
    const retry = useRef<{ timer: number | null; left: number }>({
        timer: null,
        left: RETRIES,
    });

    const reload = useCallback(() => {
        const again = () => {
            if (retry.current.timer !== null || retry.current.left <= 0) {
                return;
            }

            retry.current.left--;
            retry.current.timer = window.setTimeout(() => {
                retry.current.timer = null;
                load();
            }, RETRY_MS);
        };

        const load = () => {
            fetch(
                matchRoutes.show.url(roomId, {
                    query: screen ? { for: 'screen' } : undefined,
                }),
                {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                },
            )
                .then((response) => (response.ok ? response.json() : null))
                .then((next: RoomMatchState | null) => {
                    if (
                        next !== null &&
                        (shown.current === null ||
                            next.revision >= shown.current.revision)
                    ) {
                        shown.current = next;
                        offset.current = next.server_ms - Date.now();
                        setView(next);
                    }

                    if (next === null || next.revision < wanted.current) {
                        again();
                    } else {
                        retry.current.left = RETRIES;
                    }
                })
                .catch(again);
        };

        load();
    }, [roomId, screen]);

    useEffect(() => {
        wanted.current = Math.max(wanted.current, revision);

        if (shown.current === null || revision > shown.current.revision) {
            reload();
        }
    }, [revision, reload]);

    // The side to move's flag: read the match again once it falls.
    useEffect(() => {
        const match = view?.match ?? null;

        if (view === null || match === null || match.over) {
            return;
        }

        const left = timeLeft(
            match,
            match.turn,
            view.server_ms,
            Date.now() + offset.current,
        );
        const timer = window.setTimeout(reload, left + FLAG_GRACE_MS);

        return () => window.clearTimeout(timer);
    }, [view, reload]);

    // A taunt's cooldown or reaction ends without a revision: read again then too.
    useEffect(() => {
        const readyMs = view?.taunts.ready_ms ?? null;

        if (view === null || readyMs === null) {
            return;
        }

        const timer = window.setTimeout(
            reload,
            Math.max(0, readyMs - (Date.now() + offset.current)) + 100,
        );

        return () => window.clearTimeout(timer);
    }, [view, reload]);

    useEffect(
        () => () => {
            if (retry.current.timer !== null) {
                window.clearTimeout(retry.current.timer);
            }
        },
        [],
    );

    const serverNow = useCallback(() => Date.now() + offset.current, []);

    return { view, reload, serverNow };
}

/** The server's clock as the page estimates it, read again every `everyMs` while `running`. */
export function useTicking(
    serverNow: () => number,
    running: boolean,
    everyMs = 250,
): number {
    const [now, setNow] = useState(serverNow);

    useEffect(() => {
        setNow(serverNow());

        if (!running) {
            return;
        }

        const timer = window.setInterval(() => setNow(serverNow()), everyMs);

        return () => window.clearInterval(timer);
    }, [serverNow, running, everyMs]);

    return now;
}
