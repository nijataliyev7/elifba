# 006 — PostgreSQL in production, SQLite in development and tests, portable queries

- **Status:** Accepted, 2026-11-09 (roadmap step A1)
- **Sections:** architecture.md §10, §11
- **Why it is hard to reverse:** once a public instance holds teachers' classes and a school year of games, changing the production engine means a data migration with downtime, and every engine-specific query written in the meantime would have to be found and rewritten.

## Context

Elifba is open source: contributors must be able to run `composer setup` with no database server, and a school may want to self-host it. The owner will also run a public instance for many teachers, where several classrooms write results at the same time during school hours. Production runs as docker compose (app, Reverb, scheduler, database, proxy). The owner's precedent (`handoff`) uses SQLite; another of the owner's projects uses PostgreSQL.

## Decision

1. **Production:** PostgreSQL 17 (`postgres:17` in `docker-compose.yml`, a named volume, a nightly `pg_dump` to a backup volume).
2. **Development and tests:** SQLite (`database/database.sqlite`; in-memory for Pest). Herd needs no database service.
3. **Portability is a rule:** query builder and Eloquent only, no engine-specific SQL or functions; local dates are computed in PHP and stored (`played_on`, `week_start`); names are sorted in PHP (`AzCollator`); JSON columns are read and written whole, never queried by path.
4. **CI runs both:** the `ci` job runs the full gate on SQLite; the `pgsql` job runs the Pest suite against a `postgres:17` service.
5. A self-hosting school may keep SQLite in production; the same tests cover it.

## Consequences

- Contributors and agents work with zero setup; the public instance gets row-level locking, concurrent writers and standard backups.
- Two engines in CI cost one extra job of a few minutes and catch differences (strictness, case sensitivity, JSON, ordering) before production does.
- Some conveniences are off limits (PostgreSQL-only features, `whereJsonContains` paths, raw date functions). If one becomes necessary, this record is superseded and SQLite support is dropped explicitly.

## Alternatives rejected

- **SQLite everywhere, including the public instance:** one writer at a time and one host; outgrowing it later is exactly the hard migration this decision avoids.
- **PostgreSQL everywhere, including development:** every contributor needs a database server before running a test.
- **MySQL / MariaDB:** no advantage over PostgreSQL for this product; Azerbaijani name sorting is done in PHP either way.

## What would make us revisit

Load that one PostgreSQL server cannot carry (then read replicas or a bigger server, not a different engine), or a feature that cannot be written portably.
