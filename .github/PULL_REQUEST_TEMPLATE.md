<!-- Bu dəyişiklik nə edir və niyə? Müəllim nəyi fərqli görəcək? / What does this change do, and why? What will a teacher see differently? -->

## Nə dəyişir / What changes



## Yoxlama siyahısı / Checklist

- [ ] `composer ci:check` keçir. / `composer ci:check` passes.
- [ ] Yeni interfeys mətnləri həm `lang/az.json`, həm `lang/en.json` faylındadır. / New interface strings are in both `lang/az.json` and `lang/en.json`.
- [ ] Model və ya müqavilə dəyişibsə, `docs/architecture.md` və ya `docs/decisions/` də eyni PR-da yenilənib. / When the model or the contract changes, `docs/architecture.md` or an ADR in `docs/decisions/` is updated in this PR.
- [ ] Raund müqaviləsi dəyişibsə: PHP DTO-lar, `resources/js/game/contract.ts` və `tests/Fixtures/contract/` birlikdə. / A round contract change touches the PHP DTOs, `resources/js/game/contract.ts` and `tests/Fixtures/contract/` together.
- [ ] `{class}`, `{student}` və ya `{game}` olan yeni marşrutlar izolyasiya testindədir. / New routes with `{class}`, `{student}` or `{game}` are in the isolation test.
- [ ] Yeni aktivlər (şəkil, səs, şrift) `content/credits.json` faylında, icazəli lisenziya ilə. / New assets (pictures, sounds, fonts) have an entry in `content/credits.json` with an allowed license.
- [ ] Uşaqlar haqqında yeni məlumat yoxdur: yalnız ad və avatar; ad heç bir log, xəta, yayım və ya URL-ə düşmür. / No new data about children: a display name and an avatar only, and no name in a log, exception, broadcast or URL.
- [ ] Ekran görüntülərində yalnız uydurma adlar var. / Screenshots show made-up names only.
