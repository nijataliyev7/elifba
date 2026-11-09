# 002 — Live play: hits are client events, the board is the only writer, the phone is the teacher

- **Status:** Accepted, 2026-11-09 (roadmap step A1)
- **Sections:** architecture.md §4
- **Why it is hard to reverse:** the board, the remote, the outbox, the sync endpoint, the channel authorization and every reconnect rule depend on who sends what to whom. Changing the transport or the writer means rewriting both clients and the server's write path, and re-proving latency and idempotency.

## Context

A child touches an item on a projected wall; the teacher taps the same item on a phone at the same moment; the board must react within about 300 ms or the illusion breaks. The same board must also work by touch or mouse with no phone. Results (who solved which round, with how many mistakes) must be stored exactly once, survive a flaky school network and a reloaded tab, and must not be writable by anyone but the teacher. A QR code shown on the wall can be scanned by anyone in the room.

## Decision

1. **Transport of a hit:** the phone sends a Reverb client event (`client-hit`, via Echo `whisper`) on the private channel `private-game.{gameId}`. The board judges it locally (`engine.ts`), starts the effect in the same frame and answers `client-ack`. No PHP, database or queue is on the visible path.
2. **One writer:** the board alone writes results, for both phone hits and direct touches, by posting an ordered outbox of `hit` / `skip` events to `POST /games/{game}/sync`. `RecordBoardEvents` judges again with `RoundJudge` (authoritative), stores each event once (unique `event_id`), and computes points (`PointsRule`, PHP only). The phone never posts results.
3. **Fencing:** each board load calls `ClaimBoard`, which increments `game_sessions.board_epoch`; `/sync` rejects older epochs (409), and the phone ignores state from an older epoch. Two open board tabs cannot both write.
4. **Authorization:** the phone is logged in as the same teacher. The QR (`/pult/{code}`) and the 6-digit code only identify a board; the server accepts them only for a live game owned by the logged-in teacher or for an open guest board, and answers the same 404 otherwise. A board is either a **teacher board** (the teacher's web session) or a **guest board** on a shared computer (`/lovhe`): the teacher's phone claims it by its code and sends it a game, and the board authenticates with a bearer made from a secret that never leaves its tab; that bearer reaches only the game it shows. The channel callback allows the game's owner or the guest board showing that game, while the game is live or paused. Reverb accepts client events only from channel members (`accept_client_events_from = members`), and only from the app's origin.
5. **Protocol v1** (architecture.md §4): `client-hello`, `client-hit`, `client-student`, `client-control`, `client-ping` from the remote; `client-state`, `client-ack` from the board; `session.status` broadcast by the server when HTTP changes a game's status. Payloads carry ids only.
6. **Resilience:** the board keeps playing without the socket or without HTTP; its outbox lives in `sessionStorage` and is flushed on reconnect or reload; the remote rebuilds its screen from `client-state` after every reconnect.
7. **Budget:** p95 of the phone's `client-hit` → `client-ack` time ≤ 300 ms, which bounds tap → effect.

## Consequences

- Latency depends on the network and the server's distance from Baku, not on PHP or the database. The server's region becomes part of the hosting choice (O1).
- The judge exists twice (PHP and TypeScript); both run the same fixture cases, and the server's verdict is what is stored.
- The server cannot see a hit until the board syncs it; nothing server-side may depend on hits in real time (rankings refresh on page load, not live).
- "Only this teacher's phone can control the game" is a property of authentication, testable with an ordinary feature test of the channel callback and `/pult`.
- A teacher must be logged in on the phone (the podium share needs that anyway).

## Alternatives rejected

- **Phone → HTTP → server writes → broadcast → board:** adds a mobile HTTP request, a PHP request, a transaction and a broadcast hop to every visible reaction — roughly double the latency — and a slow server freezes the classroom.
- **Both devices write:** every hit would need cross-device de-duplication and conflicting judgements would have to be resolved.
- **The code as the credential** (a remote token without login): anyone who scans the projected QR could control the game.
- **Presence channels** to know whether the remote is there: Pusher-protocol presence de-duplicates members by user id, and both devices are the same teacher, so presence cannot tell them apart; pings do.
- **Peer-to-peer WebRTC** between phone and board: lower latency on the same Wi-Fi, but signalling, NAT traversal on school networks and a second transport to maintain.

## What would make us revisit

Measured p95 above 300 ms on real classroom networks with a nearby server, or a requirement for several boards controlled by one phone. The guest board was added on 2026-11-09 (owner's answer to A1's question about shared school computers) without changing the protocol: only the board's authentication differs.
