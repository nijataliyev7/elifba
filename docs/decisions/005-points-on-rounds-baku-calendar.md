# 005 — Points live on the solved round, dated by the Baku school calendar; rankings are derived

- **Status:** Accepted, 2026-11-09 (roadmap step A1); scoring and visibility confirmed by the owner the same day
- **Sections:** architecture.md §5
- **Why it is hard to reverse:** every solved round stores its points, its Baku date and its week at the moment it is solved. Changing the rule or the calendar later does not change history unless all stored rounds are recomputed, and teachers will already have posted podiums based on them.

## Context

Each class has a daily and a weekly ranking and a podium for places 1–3 that the teacher posts to WhatsApp status. Children are 6–10; the teacher chooses whose turn it is at the board. Schools run on Baku time (UTC+4, no daylight saving since 2016); the school week starts on Monday. The app stores timestamps in UTC and must give the same rankings on SQLite (development, tests) and PostgreSQL (production).

## Decision

1. **Points per solved round**, credited to the student at the board when the round is solved: 3 with no wrong hit, 2 with one, 1 with more; never negative; 0 for a skipped round; stored but credited to no one when the whole class plays without a student selected. The rule exists only in `Play\Support\PointsRule`.
2. **Dates at write time:** when a round is solved, `SchoolCalendar` (time zone `config('elifba.timezone')` = `Asia/Baku`) stores `played_on` (the local date) and `week_start` (the Monday of that date) on `game_rounds`.
3. **Rankings are derived**, never stored: the daily ranking sums `points` by student over `played_on = D`, the weekly over `week_start = W`, for active students of the class. Queries filter on these date columns only.
4. **Ties share a place, dense ranking** (10, 10, 8, 7 → 1, 1, 2, 3). No time-based tie-breaker. Names within a place are listed alphabetically (Azerbaijani order) for display only.
5. Students with 0 points are listed without a place; archived and deleted students never appear. The podium is places 1–3 (several children per place when tied) and is empty when nobody has points.
6. **Visibility:** the full ranking is shown only on the teacher's own pages; the board shows, at the end of a game, the class's stars and today's podium — never the full list.

## Consequences

- One indexed `GROUP BY` per ranking; identical results on SQLite and PostgreSQL; no ledger to keep consistent.
- History is exact: a change to `PointsRule` or to the time zone applies to new rounds only. A deliberate recalculation would be a one-off command over `game_rounds`.
- More children reach the podium after ties, and nothing rewards speed — which the teacher, not the child, controls.
- Tests travel in time across Baku midnight (20:00 UTC) and Sunday → Monday.

## Alternatives rejected

- **A separate points ledger:** a second write to keep in sync, for no query that needs it.
- **Time-zone arithmetic in SQL** (`AT TIME ZONE`, `date(…, '+4 hours')`): different on each engine and easy to get wrong at midnight.
- **Competition ranking (1, 1, 3):** a tie for first leaves no second place on a children's podium.
- **Speed or streak bonuses:** reward the fastest readers and punish the ones who need practice most.
- **Points per hit:** a sentence round would be worth four letter rounds, and wrong taps would need negative points to stay fair.

## What would make us revisit

Rankings across classes or schools, per-school time zones in other countries (the setting is already in config; history would keep the old dates), or teacher feedback from the classroom trial (O3) that the scoring discourages children.
