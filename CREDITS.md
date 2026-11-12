# Credits

<!-- Generated from content/credits.json by `php artisan content:credits`. Do not edit by hand; a test fails when it is stale. -->

Elifba's code and its own art are under the [MIT License](LICENSE). Files made by others keep their own licenses: the Fluent Emoji pictures in `content/assets/fluent/` are © Microsoft Corporation under the MIT License, the fonts in `resources/fonts/` under the SIL Open Font License 1.1, and Kenney's avatars and sounds are CC0 1.0 (public domain). The board's voice in `content/assets/voice/` is synthetic speech made for Elifba with ElevenLabs, under the MIT License too.

The app shows the same list on its `/credits` page.

## Files

### Animal Pack Redux

- Files: `content/assets/avatars/*`
- Author: Kenney (kenney.nl)
- License: [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) (`CC0-1.0`)
- Source: <https://opengameart.org/content/animal-pack-redux>
- Changes: Cut from the pack's vector sheet Vector/roundOutline.svg into one SVG per animal; shapes and colours unchanged.

### Interface Sounds 1.0

- Files: `content/assets/sounds/pop.mp3`, `content/assets/sounds/chime.mp3`, `content/assets/sounds/bong.mp3`, `content/assets/sounds/success.mp3`
- Author: Kenney (kenney.nl)
- License: [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) (`CC0-1.0`)
- Source: <https://opengameart.org/content/interface-sounds>
- Changes: Converted from OGG to MP3 and renamed: drop_002 → pop, glass_001 → chime, bong_001 → bong, confirmation_004 → success.

### 85 Short music jingles

- Files: `content/assets/sounds/pluck-down.mp3`, `content/assets/sounds/pluck-up.mp3`, `content/assets/sounds/pluck-tada.mp3`
- Author: Kenney (kenney.nl)
- License: [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) (`CC0-1.0`)
- Source: <https://opengameart.org/content/85-short-music-jingles>
- Changes: Converted from OGG to mono MP3, silence trimmed, levelled to −13 LUFS with a short fade-out, and renamed: jingles_PIZZA09 → pluck-down, jingles_PIZZA10 → pluck-up, jingles_PIZZA16 → pluck-tada.

### Celebration and train sounds, synthesised for Elifba

- Files: `content/assets/sounds/bells.mp3`, `content/assets/sounds/marimba.mp3`, `content/assets/sounds/harp.mp3`, `content/assets/sounds/kalimba.mp3`, `content/assets/sounds/train-arrive.mp3`, `content/assets/sounds/train-leave.mp3`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### Fluent Emoji (Color), commit 1ffb34c

- Files: `content/assets/fluent/*`
- Author: Microsoft Corporation
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/microsoft/fluentui-emoji>

### National and cultural symbols, drawn in Fluent Emoji's manner; the State Flag to its official construction and colours

- Files: `content/assets/symbols/*`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### Word pictures Fluent Emoji lacks, drawn in its manner

- Files: `content/assets/fruit/*`, `content/assets/animals/*`, `content/assets/balloons/*`, `content/assets/train/*`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### City maps in Fluent Emoji's manner (outline from Natural Earth, public domain) and the Qız qalası backdrop

- Files: `content/assets/cities/*`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### Colours: one round swatch in each of eleven colours, drawn in Fluent Emoji's manner

- Files: `content/assets/colors/*`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### Things to count Fluent Emoji lacks, drawn in its manner

- Files: `content/assets/numbers/*`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### Board backdrops in Fluent Emoji's manner: a meadow by a farm (animals), an orchard (fruit), a sky with clouds (colours), soft hills at dawn (numbers)

- Files: `content/assets/animals/cemenlik.svg`, `content/assets/fruit/bag.svg`, `content/assets/colors/goy-uzu.svg`, `content/assets/numbers/tepeler.svg`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### Andika 7.000

- Files: `resources/fonts/andika/*`
- Author: SIL Global
- License: [SIL Open Font License 1.1](https://openfontlicense.org/) (`OFL-1.1`)
- Source: <https://software.sil.org/andika/>

### Inter

- Files: `resources/fonts/inter/*`
- Author: The Inter Project Authors
- License: [SIL Open Font License 1.1](https://openfontlicense.org/) (`OFL-1.1`)
- Source: <https://github.com/rsms/inter>

### The board's voice: every prompt, the praise and the retry phrase, in Azerbaijani

- Files: `content/assets/voice/*`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://elevenlabs.io/docs/overview/capabilities/text-to-speech/eleven-v4>
- Changes: A synthetic voice, not a person's recording: made with ElevenLabs Eleven v4 (eleven_v4, language az) in the Voice Library voice «Nigar – Ad Read» under a paid plan, in November 2026; trimmed and levelled with ffmpeg

### The board's voice: the first names of Elifba's name list, in Azerbaijani

- Files: `content/voice-names/*`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://elevenlabs.io/docs/overview/capabilities/text-to-speech/eleven-v4>
- Changes: A synthetic voice, not a person's recording: made with ElevenLabs Eleven v4 (eleven_v4, language az) in the Voice Library voice «Nigar – Ad Read» under a paid plan, in November 2026, one clip per name of content/names.json (our own list, seeded from Wikidata, CC0); trimmed and levelled with ffmpeg
