# Contributing to Elifba

Thanks for helping. Bug reports, translations, content (words, sentences, syllable checks) and pull requests are all welcome. Teachers are especially welcome: you know what a first-grader can read.

## Issues

Open an issue with one of the forms: [a bug](.github/ISSUE_TEMPLATE/1-bug.yml), [an idea](.github/ISSUE_TEMPLATE/2-idea.yml) or [a content correction](.github/ISSUE_TEMPLATE/3-content.yml) (a word, a syllable split, a sentence or a picture). Write in Azerbaijani or English. Never paste children's names, and never attach a screenshot that shows real students' names: reproduce the problem in a class with made-up names. Security problems go privately, as [SECURITY.md](SECURITY.md) explains.

## Set up

```bash
git clone https://github.com/<you>/elifba.git
cd elifba
composer setup     # install, .env, key, migrate, npm install, build
composer dev       # or let Laravel Herd serve http://elifba.test
```

SQLite is all you need for development. Read [`docs/architecture.md`](docs/architecture.md) before a larger change: it is the contract the code follows, and a change that departs from it updates it in the same pull request.

## Before you open a pull request

The [pull request template](.github/PULL_REQUEST_TEMPLATE.md) has the checklist reviewers go through.

```bash
composer ci:check
```

This is what CI runs: `vp check` (oxlint + oxfmt), `tsc --noEmit`, Vitest, Pint, PHPStan at level 8 and the Pest suite. CI also runs the Pest suite on PostgreSQL 17 and smoke-tests the Docker stack (`scripts/docker-smoke.sh`). `npm run check:fix` and `composer lint` fix formatting for you.

- Every change comes with a test: feature tests for HTTP, unit tests for pure logic.
- Code lives in `app/Domain/{Language,Content,Classroom,Play,Rankings}`; a domain uses only the ones before it.
- Only Actions write. Controllers stay thin: validate, call one action, shape the response.
- PHPStan stays at level 8 with no baseline: fix the code, do not silence the error.
- No engine-specific SQL: the same queries run on SQLite and PostgreSQL.
- Pages make no third-party requests: no CDNs, external fonts, analytics or trackers.

## Translations

Interface strings live in `lang/az.json` (the base) and `lang/en.json`, with dotted keys such as `settings.profile.title`. Every key exists in both files, with the same `:placeholders`; `tests/Unit/TranslationsTest.php` checks it, together with the framework messages in `lang/{az,en}/*.php`. Plural forms use `{one}…|{other}…`.

Write the Azerbaijani a teacher would say, with the real letters (ə, ğ, ı, ö, ş, ü, ç — never the Cyrillic ә). To add a language: copy `lang/en.json` and `lang/en/`, translate them, and add the locale to `locales` in `config/elifba.php`.

Game content (letters, words, sentences, prompts) is not interface text: it is always Azerbaijani and lives in [`content/`](content/). `php artisan content:validate` checks it.

## Children's privacy

Never add a field about children beyond a display name and an avatar, and never put a child's name in a log, an exception, a broadcast or a URL. See [architecture §7](docs/architecture.md#7-childrens-privacy).

## Assets

Only CC0, CC BY, CC BY-SA, OFL or MIT assets, each listed in `content/credits.json`. Nothing non-commercial, no-derivatives or of unknown origin. See [architecture §8](docs/architecture.md#8-assets-and-licenses).

## Commits and pull requests

- Small, focused pull requests with a clear description of the user-visible change.
- Conventional commit subjects are appreciated: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`.

By contributing you agree that your work is released under the [MIT license](LICENSE).
