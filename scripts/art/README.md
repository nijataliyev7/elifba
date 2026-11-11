# Picture art

The packs' pictures move from OpenMoji to Microsoft's Fluent Emoji, the Color style, with own art drawn in its manner ([ADR 014](../../docs/decisions/014-picture-art-style.md)). This folder holds the plan and the tools for the sessions that carry it out:

- `inventory.csv` — one row per picture, figure and backdrop the eight packs reference (219).
- `check-inventory.php` — checks the file against the packs.
- `copy-fluent.php` — copies a session's Fluent pictures from the download.
- `preview.php` — shows a pack's pictures in copies of the board's tile.

## The download

Fluent Emoji comes from <https://github.com/microsoft/fluentui-emoji> at commit `1ffb34c752ecf5d402f04cfb4b392c77f57c54bc` (2026-08-24; the repository has no releases). Its `LICENSE` is MIT, "Copyright (c) Microsoft Corporation"; read it again after downloading. Fetch only the Color SVGs and the licence, outside the repository (§8: a download is never committed; only the files used are copied in):

```sh
git clone --filter=blob:none --no-checkout https://github.com/microsoft/fluentui-emoji.git /tmp/fluentui-emoji
cd /tmp/fluentui-emoji
git sparse-checkout set --no-cone '/LICENSE' '/assets/*/metadata.json' '/assets/*/Color/*.svg' '/assets/*/Default/Color/*.svg'
git checkout 1ffb34c752ecf5d402f04cfb4b392c77f57c54bc
```

Each emoji is a folder under `assets/` with a `metadata.json` whose `unicode` names its code point (`"1f408"`, `"1f9d1 200d 1f3eb"`). An emoji with skin tones has its files under `Default/`, `Light/` and so on; Elifba takes `Default`.

## inventory.csv

| Column           | Meaning                                                                                                                                                          |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pack`           | the pack's key                                                                                                                                                   |
| `kind`           | `word` (a word's `image`), `figure` (in `style.figures` only), `word+figure` (both), `backdrop` (`style.backdrop`)                                               |
| `word_or_figure` | the word as the pack writes it; for a figure only, what it shows                                                                                                 |
| `current_file`   | the path the pack references before the move, under `content/assets/`                                                                                            |
| `new_source`     | `fluent:{code point}` or `own`                                                                                                                                   |
| `new_file`       | the path the pack references after the move: `fluent/{code point}.svg`, or the own file (redrawn in place, new, or shared, as `colors/qirmizi.svg` for balloons) |
| `fluent_file`    | for a Fluent picture, its path in the download                                                                                                                   |
| `session`        | the session that moves the row: 2 animals, fruit, colors, numbers; 3 the backdrops it added; 4 balloons, train, symbols; 5 cities                                |
| `notes`          | why a picture is own art, what to draw, what to delete                                                                                                           |

The rows were made by a script from the packs and the download on 2026-11-11: a word that referenced an OpenMoji file takes the same code point from Fluent when Fluent has it, the `numbers` pack's things to count take the emoji that shows them, colour words are own swatches, and everything else is own art. Counts (Fluent / own rows): animals 38/3, fruit 14/20, colors 0/11, numbers 9/1, balloons 14/15, train 20/13, symbols 0/27, cities 0/30. Session 3 added one own row per backdrop it drew (animals, fruit, colors, numbers).

## How a session uses it

For the packs of session N:

1. For every `fluent:` row, copy `fluent_file` from the download to `content/assets/{new_file}`, unmodified, and put the download's `LICENSE` beside the files as `content/assets/fluent/LICENSE.txt` (once). Add the `credits.json` entry of ADR 014 decision 6 (once). The script does the copying, checks each file's `metadata.json` against the row's code point and refuses to overwrite a file with other bytes:

    ```sh
    php scripts/art/copy-fluent.php /tmp/fluentui-emoji --session=N
    ```

2. For every `own` row, draw `new_file` by ADR 014's style guide, or redraw it in place.
3. In the pack's JSON, replace every `current_file` with its `new_file`, in the words' `image` and in `style.figures`, and raise the pack's `version` by one.
4. Delete the own files a note says to delete and the OpenMoji files no pack references any more, and update the credits entries of the folders that changed (the titles that say "drawn in OpenMoji's style").
5. Check each pack's pictures together, at 96 and 384 px — a new drawing can be shown before it is in the pack:

    ```sh
    php scripts/art/preview.php fruit /tmp/fruit.html content/assets/fruit/heyva.svg && open /tmp/fruit.html
    ```

6. Run the checks:

    ```sh
    php scripts/art/check-inventory.php --done=N
    php artisan content:validate
    php artisan content:credits
    ```

    Without `--done`, the check only asks that every reference has a row and every row is well formed; with `--done=N`, every row of sessions up to N must be moved and its file must exist.

Keep the rows as they are when a session is done: `current_file` records what was there before. A row that has to change (a better emoji, a word added to a pack) changes in the same commit as the pack.
