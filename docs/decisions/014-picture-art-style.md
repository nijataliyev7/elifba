# 014 — Picture art style: Fluent Emoji (Color), and own art drawn like it

- **Status:** Accepted, 2026-11-11 (the owner's choice on a side-by-side sample sheet; built pack by pack in the picture-style sessions 2, 4 and 5)
- **Sections:** architecture.md §8, §2 "Figures", §3 "Where content lives" (`colors`, `numbers`)
- **Amends:** architecture.md §8: the rows "Word pictures" and "Pictures OpenMoji lacks", "Own art style", the look of "City maps" and "What share-alike means here"; [012](012-picture-and-number-games.md) decision 9: the colours keep one plain shape, drawn in the new manner, and the numbers' things to count take Fluent's emoji where one shows them. What stays: one look per pack, the SVG safety rule, no text or digit in a picture, a credit for every file, the flag's construction and colours, nothing on the share card but CC0 and own art.
- **Why it is hard to reverse:** every pack's pictures change (215 references in eight packs, 81 own drawings redrawn or added), every pack's `version` goes up, and the credits, the READMEs and `/credits` lose OpenMoji's share-alike wording. Going back means drawing the own art a third time.

## Context

The word pictures and the figures are OpenMoji 17 colour SVGs (CC BY-SA 4.0) in `content/assets/openmoji/` (99 pictures and its `LICENSE.txt`); what OpenMoji lacks is own art in OpenMoji's style — a black 2-unit outline and flat fills on a 72×72 grid — under `content/assets/{pack}/`. Per pack (OpenMoji / own): animals 39/2, fruit 16/18, balloons 22/7, train 22/11, colors 0/11, numbers 0/10, symbols 0/27, cities 0/30 (29 generated maps and the Qız qalası backdrop). The owner wants the games to look more professional (2026-11-11).

Five candidates were put on one sample sheet: the same ten subjects (pişik, it, zürafə, fil, at, alma, armud, üzüm, banan, çiyələk) in a copy of the board's picture tile (`styles/picture.ts`: the pack colour, face shade 0.86, radius 0.16 of the side, picture 0.8 of the side) at 384 and 96 px on the board's background, plus heyva and zoğal — in no emoji set — drawn in each style:

| Set                 | Licence (checked in the download)                                                                            | Look                                      | Of the 99 code points used today                                                                            |
| ------------------- | ------------------------------------------------------------------------------------------------------------ | ----------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| OpenMoji 17         | CC BY-SA 4.0                                                                                                 | black outline, flat                       | 99                                                                                                          |
| Fluent Emoji, Color | MIT, `LICENSE`: "Copyright (c) Microsoft Corporation"                                                        | no outline, soft gradients and light      | 94: no family emojis (1F468-200D-1F466, 1F469-200D-1F467), none of OpenMoji's own extras (E009, E0C0, E0C4) |
| Fluent Emoji, Flat  | MIT, the same files                                                                                          | Color's shapes, flat fills                | 94                                                                                                          |
| Twemoji (jdecked)   | graphics CC BY 4.0 (`LICENSE-GRAPHICS`)                                                                      | flat, no outline                          | 96                                                                                                          |
| Noto Emoji, 2D      | Apache-2.0 (`2D/svg/LICENSE`; the repository's root `LICENSE` is the fonts' OFL 1.1) — not on the allow list | flat with light spots, the most childlike | 96                                                                                                          |

Fluent's 3D style was not shown: it is PNG at 256 px and would blur at `FIGURE_RASTER` (416 px). The owner chose Fluent Color (2026-11-11).

## Decision

1. **One set: Microsoft Fluent Emoji, the Color style**, from `github.com/microsoft/fluentui-emoji` at commit `1ffb34c` (2026-08-24; the repository has no releases). Every word picture and every figure of the eight packs is a Fluent Color SVG or own art drawn in its manner (the guide below). Fluent's Flat, High Contrast and 3D styles are not used.
2. **The files.** Copied unmodified into `content/assets/fluent/` and named by code point, as `openmoji/` is: uppercase hex joined by `-`, without `FE0F` (`1F408.svg`, `1F9D1-200D-1F3EB.svg`). In the repository a file is `assets/{Name}/Color/{name}_color.svg`; an emoji with skin tones is taken in its `Default` variant, `assets/{Name}/Default/Color/{name}_color_default.svg` — yellow, as OpenMoji's default people are today. Microsoft's `LICENSE` lies beside them as `content/assets/fluent/LICENSE.txt`. A file keeps its own `width`, `height` and `viewBox` (two are 32×33: 1F418, 1F98F); the board scales a picture by its longer side. `scripts/art/inventory.csv` names each file (91 distinct ones, 2.1 MB) and where it comes from.
3. **Which picture.** §8's rule stays: a word takes an emoji only when it clearly shows that word. Where Fluent has one that does, it also replaces own art (the `numbers` pack's ulduz, ürək, top, gül, balıq, kəpənək, göbələk, bulud and şar; its own files are deleted). Where it has none — OpenMoji's extras (göyərçin, nar, portağal), the families (ana, ata), the Azerbaijani fruit, the toys and places without an emoji, the national symbols, the cities — the picture is own art in `content/assets/{pack}/`, named after the word in ASCII (`fruit/heyva.svg`, `animals/goyercin.svg`). 1F54A (a dove with an olive branch) is a sign, not the bird a child sees in the yard, so «göyərçin» is drawn.
4. **Colour swatches are own art in every pack.** Fluent's coloured circles are lit spheres whose light and shade drift in hue (the red's light is pink, the yellow's shade ochre, the green's teal): for a child naming a colour, one circle would show two. The eleven words of `colors` and the colour words of `balloons` are one swatch per colour (the guide's "Colour swatches") in `content/assets/colors/`; `balloons` references those files and adds one of its own, `balloons/mavi.svg`.
5. **One look per pack, switched pack by pack.** A pack moves to the new set whole, in one change, with its `version` + 1: session 2 the picture packs (animals, fruit, colors, numbers), session 4 the reading packs (balloons, train, symbols), session 5 the cities (the generator and the backdrop) and then OpenMoji's removal. Between sessions two packs may look different; one pack never does. Each session checks its rows with `php scripts/art/check-inventory.php --done=N` (`scripts/art/README.md`).
6. **Licence and credits.** MIT is on the allow list; `License` gains nothing. The `content/credits.json` entry:

    ```json
    {
        "files": ["content/assets/fluent/*"],
        "title": "Fluent Emoji (Color), commit 1ffb34c",
        "author": "Microsoft Corporation",
        "source": "https://github.com/microsoft/fluentui-emoji",
        "license": "MIT"
    }
    ```

    The glob covers `LICENSE.txt` too, as OpenMoji's does. MIT asks that the copyright and permission notice go with the copies: that is the `LICENSE.txt` beside them and this entry on `/credits` and in `CREDITS.md`. No attribution sentence is needed on the board, in the READMEs or on `/credits`. Own art stays MIT, author "Elifba contributors", source the repository URL. A file that copies a Fluent file and changes it (none is planned) gets its own entry, author "Microsoft Corporation; changed by Elifba contributors", with `changes`. The share card still uses only CC0 and own art: a picture composed into a PNG cannot carry the notice.

7. **When OpenMoji is unused (session 5).** Delete `content/assets/openmoji/` with its `LICENSE.txt`, and its `credits.json` entry. `CreditsDocument::openMoji()` then returns null, so `php artisan content:credits` drops the OpenMoji section from `CREDITS.md` and `/credits` stops showing it. Rewrite by hand what names OpenMoji: `CreditsDocument::STATEMENT`; `credits.licenses.body` in `lang/az.json` and `lang/en.json`; the licence paragraph of `README.md` and `README.az.md`, whose OpenMoji attribution line goes and whose statement becomes "the code and Elifba's own art are MIT; the Fluent Emoji pictures in `content/assets/fluent/` are © Microsoft Corporation under MIT; the fonts OFL 1.1; Kenney's avatars and sounds CC0"; the credit titles that say "drawn in OpenMoji's style" or "OpenMoji's palette"; and in §8 the two rows, "Own art style", the look of "City maps" and "What share-alike means here" (no share-alike asset is left). Remove what only served OpenMoji: `CreditsDocument::OPENMOJI_*` and `openMoji()`, the `openmoji` prop and section of `pages/credits.tsx`, the `credits.openmoji.*` keys in both languages, and the tests that pin them. `License::CcBySa` and `ContentValidator::SHARE_ALIKE_FOLDER` stay: the allow list does not shrink, and a share-alike file added later still has one folder. The share card's medal colours (`place-colors.ts`, `card-decoration.tsx`) are hex values taken from OpenMoji's palette, not OpenMoji files; they stay.
8. **The flag** keeps its construction, proportions and official colours (§8 "National symbols"): flat fills with no gradient, highlight or blur on the field; crescent and star not outlined. On the 32 grid its field is 28 × 14 at (2, 9), every measure of the construction scaled from h = 14. Its frame stays outside the field, in `#33293D` (the guide's darkest neutral) instead of black. The `symbols` pack keeps `sparkle` for every item.
9. **City maps** stay generated by `scripts/city-maps/generate.php`, restyled in session 5: the 32 grid, the land one surface in the guide's leaf green with a 0.5-unit rim of its shade colour instead of a black outline, the pin a small red sphere (the red triple, with a highlight) on a needle in the stone triple, its tip on the city. The Qız qalası backdrop keeps §8 "Backdrops" (160×90, low contrast), recoloured to the guide's palette.

## Own-art style guide

The aim: 81 drawings that sit among Fluent's pictures without being picked out. The reference files named below by code point are in the download (`scripts/art/README.md`) and, once imported, in `content/assets/fluent/`; when a drawing shows a material a reference file shows, it takes that file's gradient stops.

- **Canvas.** `<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">`, then `<!-- {word}: {what it shows}. Elifba own art, MIT. -->`. The subject fills the square as Fluent's do: its longer side 28–30 units, inside 1–31, a standing subject's feet near y = 30, a round one centred. One subject, whole — a whole animal, not a face, unless the word is a face.
- **No outline.** No stroke around a shape. An edge is where a lighter surface meets a darker one. Pupils and the darkest details are `#3A234A` (Fluent's deep violet), never `#000`, with a white catchlight of 0.4–0.6 units.
- **Light** comes from the top left, always. A surface is up to three layers, in this order:
    1. the base: the shape filled with a `radialGradient` centred at about 30 % across and 25 % down its box and reaching its far corner (Fluent exports `gradientUnits="userSpaceOnUse"` with a `gradientTransform`; `cx="0.3" cy="0.25" r="0.8"` in bounding-box units is the same), stops light (0) → base (about 0.5) → shade (1);
    2. the shade, if the surface is large: the same shape again, a `radialGradient` from its bottom right, the shade colour at opacity 0.5–0.8 fading to 0 by offset 0.6–1;
    3. the highlight: one small soft shape in the top-left third of the surface, a near-white tint of the base at opacity 0.6–0.9, blurred by `feGaussianBlur` with `stdDeviation` 0.25–0.6.

    Thin parts under 2 units wide (stalks, twigs, legs, strings) take a two-stop `linearGradient` along their length, shade → base, or one flat fill; a line is a stroke with round caps, 0.6–1.8 units wide. Shade turns towards red and violet and light towards yellow and white, as in Fluent; never a grey or black shade, and no drop shadow (the tile draws one).

- **Palette** (light · base · shade, sampled from Fluent Color files):

    | Material                            | Light     | Base      | Shade                                   | Reference           |
    | ----------------------------------- | --------- | --------- | --------------------------------------- | ------------------- |
    | red: fruit, berries, cloth, the pin | `#FF6180` | `#F24756` | `#B22945`                               | 1F534, 1F352        |
    | orange                              | `#FFA35F` | `#FF7E51` | `#EE534A`                               | 1F7E0, 1F34A        |
    | yellow, gold, brass                 | `#FFF246` | `#F8CA4D` | `#C79738`                               | 2B50, 1F7E1, 1F514  |
    | leaf green                          | `#CFFF73` | `#96C860` | `#74A41C`                               | 1F33F, 1F353        |
    | blue                                | `#62A5E9` | `#4DA0DA` | `#3557C3`                               | 1F535               |
    | sky, water                          | `#8BEFFF` | `#6CCEFF` | `#6E99FF`                               | 1F4A7               |
    | purple                              | `#A087D8` | `#8971BD` | `#7047B7`                               | 1F7E3, 1F347        |
    | brown: fur, earth                   | `#AA7E73` | `#8C5E51` | `#703B50`                               | 1F7E4, 1F43B        |
    | wood                                | `#E8A66F` | `#C48462` | `#5F4341`                               | 1F6AA, 1FAB5        |
    | stone, metal                        | `#DAD9DB` | `#C2BDC9` | `#988EA1`                               | 1FAA8               |
    | white: cloud, cloth, paper          | `#FCFBFE` | `#E8E8E9` | `#CDCDCE`, tinted `#D0C9EE` at the edge | 2601                |
    | black: hair, tyres                  | `#6A6571` | `#554A63` | `#33293D`                               | 26AB                |
    | skin (Default)                      | `#FFE134` | `#FFC532` | `#E59B1F`                               | 1F468, 1F466, 1F9D1 |

- **People** are drawn as the imported ones (nənə 1F475, baba 1F474, müəllim 1F9D1-200D-1F3EB): the Default yellow skin, with face, hair and skin gradients copied from 1F469, 1F468, 1F467 and 1F466. «ana» is a woman with a girl, «ata» a man with a boy, «bacı» two girls and «qardaş» two boys, the elder taller.
- **Shapes** are rounded and a little chubby, as Fluent's: no corner sharper than a 0.5-unit radius, an animal's head a little large, a three-quarter or side view.
- **Readable at 96 px.** Render the file at 77 px (the picture in a 96 px tile): its silhouette alone must name it. Nothing smaller than 1 unit carries meaning, a surface has at most three layers, and texture finer than 1 unit is left out (a strawberry's seeds yes, fur strokes no).
- **Plain SVG.** No text, letter or digit, as a `<text>` or drawn; no `<image>`, `<style>`, `<script>`, `<foreignObject>`, class, event attribute or `href` other than `#…` (§8 "SVG safety"); filters only `feGaussianBlur` (the `feFlood` and `feBlend` Fluent's exporter writes around it are fine); `mask` and `clipPath` only where a shape cannot be drawn otherwise. Ids start with the file's name (`heyva_body`). Coordinates have at most two decimals, and a file stays under 24 KB.
- **Colour swatches.** Each colour word is `<circle cx="16" cy="16" r="14"/>` in the three layers above, but its hue never changes: light is the base mixed 40 % with white, shade the base mixed 25 % with black, the base at offset 0.55 (bounding-box `cx="0.35" cy="0.3" r="0.75"`); the highlight is an ellipse at (11.5, 10.5), 3.2 × 2.2, turned −35°, white at 0.6, blur 0.6. The bases are today's hues, already checked in pairs on a projector: qırmızı `#D22F27`, sarı `#FCEA2B`, yaşıl `#5C9E31`, göy `#1E50A0`, mavi `#92D3F5` (balloons only), narıncı `#E27022`, bənövşəyi `#8967AA`, çəhrayı `#FFA7C0`, qəhvəyi `#6A462F`, boz `#9B9B9A`; qara is light `#5A5A5A`, base `#2A2A2A`, shade `#0E0E0E`, and ağ light `#FFFFFF`, base `#F7F7F7`, shade `#C8C8C8`, so its edge shows on a light tile. The pairs red–orange, red–pink, blue–purple, göy–mavi and grey–white are checked again on a projector once drawn.
- **Before a drawing is committed**, put it in a tile at 384 and 96 px between its pack's Fluent pictures (the sample sheet's layout, `scripts/art/README.md`). If a viewer picks it out as the odd one, redraw it.

## Consequences

- The pictures gain light and depth, and a pack's imported and own pictures share one manner. Fluent's pictures fill their square, where OpenMoji's keep to a 4–68 safe area, so in the same tile a picture looks about a tenth larger; the board's presentation (session 3) may retune the tile, never the files.
- The 91 Fluent files weigh 2.1 MB (median 20 KB, the largest 77 KB) against OpenMoji's 0.5 MB. A board loads only its pack's pictures and rasterises each once at 416 px; the blur filters render in every browser a classroom uses.
- Drawing is harder than in OpenMoji's flat style: 81 own drawings — animals 3, fruit 20, colors 11, numbers 1, balloons 6 (its other nine colour words use the `colors` files), train 13, symbols 27 — plus the restyled map generator and the backdrop. The guide and the reference files keep them alike.
- No licence duty reaches the screen, and once OpenMoji is gone no share-alike asset is left.
- People stay yellow-skinned, as today.

## Alternatives rejected

- **Keep OpenMoji:** the black outline and flat fills read as a colouring book on a projector, and the share-alike wording stays in the READMEs, `CREDITS.md` and `/credits`.
- **Fluent Flat:** the same coverage and licence, and the easiest to draw alike, but flat without light; the owner chose the depth of Color.
- **Twemoji:** flat like Fluent Flat, with an attribution duty (CC BY 4.0); its 96 code points are two more than Fluent's, both families, which are two drawings.
- **Noto 2D:** the most childlike, but Apache-2.0 is not on the allow list (adding it brings a NOTICE duty), and its repository mixes licences (OFL at the root, Apache-2.0 for the SVGs).
- **Fluent 3D:** PNG at 256 px blurs at 416 px, and a rendered 3D look cannot be drawn alike for 81 own pictures.
- **Fluent's coloured circles as colour pictures:** the hue drifts within one circle (decision 4).
- **A set per pack** (Noto's animals, Fluent elsewhere): the app should look like one product, and pictures repeat across packs (balıq, kəpənək, şar, top).

## What would make us revisit

A projector test in a classroom where the gradients wash out; many new words that Fluent lacks, so that own art outweighs the imported pictures (Flat would then be cheaper to draw); or a wish to put pictures on the share card.
