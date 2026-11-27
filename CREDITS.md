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

- Files: `content/assets/fruit/*`, `content/assets/animals/*`, `content/assets/dinosaurs/*`, `content/assets/balloons/*`, `content/assets/train/*`, `content/assets/space/*`, `content/assets/vehicles/*`, `content/assets/nature/*`, `content/assets/school/*`
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

### Shapes for the adults' memory rounds: twelve plain shapes in Fluent Emoji's manner, each with a form of its own

- Files: `content/assets/rooms/*`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### Sounds of the adults' room games made from recordings: the screen's countdown, start, study, clock, bell, roll and fanfare, and a phone's tap and «right» (marimba, glockenspiel, harp, timpani, snare and bass drum, cymbal, tubular bell, log drums, claves, cello, trumpets, horn, trombone)

- Files: `content/assets/rooms/sounds/countdown.mp3`, `content/assets/rooms/sounds/go.mp3`, `content/assets/rooms/sounds/study.mp3`, `content/assets/rooms/sounds/clock.mp3`, `content/assets/rooms/sounds/close.mp3`, `content/assets/rooms/sounds/roll.mp3`, `content/assets/rooms/sounds/fanfare.mp3`, `content/assets/rooms/sounds/tap.mp3`, `content/assets/rooms/sounds/right.mp3`
- Author: Sam Gossner and Simon Dalzell (Versilian Studios), VS Chamber Orchestra: Community Edition; sample cutting by Elan Hickler (Soundemote)
- License: [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) (`CC0-1.0`)
- Source: <https://github.com/sgossner/VSCO-2-CE>
- Changes: Downloaded from commit 440300901dfe9275fd84e0b7763af1f8443ae62e and checked by SHA-256; mixed down to mono, trimmed, faded, some pitched by resampling, layered, given a small synthetic room where noted and levelled to one loudness, then encoded as 96 kb/s MP3 by scripts/room-sounds/make.py, whose README lists each cue's recordings

### Sounds of the adults' room games synthesised by scripts/room-sounds/make.py: the screen's question, ranking, place and join, a board game's capture and crown, and a phone's undo, «answer sent», «wrong», a draw offer and a domino game's turn

- Files: `content/assets/rooms/sounds/question.mp3`, `content/assets/rooms/sounds/ranking.mp3`, `content/assets/rooms/sounds/place.mp3`, `content/assets/rooms/sounds/join.mp3`, `content/assets/rooms/sounds/capture.mp3`, `content/assets/rooms/sounds/crown.mp3`, `content/assets/rooms/sounds/undo.mp3`, `content/assets/rooms/sounds/sent.mp3`, `content/assets/rooms/sounds/wrong.mp3`, `content/assets/rooms/sounds/offer.mp3`, `content/assets/rooms/sounds/turn.mp3`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### A board game's move: a wooden chess piece set down on a wooden board, recorded a little way off («Chess Pieces Move (Far)»)

- Files: `content/assets/rooms/sounds/move.mp3`
- Author: JJTaynos
- License: [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) (`CC0-1.0`)
- Source: <https://freesound.org/people/JJTaynos/sounds/733932/>
- Changes: Downloaded without a login as Freesound's preview of sound 733932 (733932_15803939-hq.mp3) and checked by SHA-256; one touch cut from 14.36 s, below 70 Hz and above 9 kHz removed, faded in over 1 ms and out over 35 ms, levelled and encoded as 96 kb/s MP3 by scripts/room-sounds/make.py

### A domino game's play: one domino put on a table («Domino pieces 1»)

- Files: `content/assets/rooms/sounds/tile.mp3`
- Author: Macif
- License: [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) (`CC0-1.0`)
- Source: <https://freesound.org/people/Macif/sounds/329099/>
- Changes: Downloaded without a login as Freesound's preview of sound 329099 (329099_3035766-hq.mp3) and checked by SHA-256; 170 ms cut from 16.71 s, below 70 Hz and above 9 kHz removed, faded in over 1 ms and out over 35 ms, levelled and encoded as 96 kb/s MP3 by scripts/room-sounds/make.py

### A domino game's draw: a tile taken up from the table («domino_draw»)

- Files: `content/assets/rooms/sounds/draw.mp3`
- Author: UnycodeAdmin
- License: [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) (`CC0-1.0`)
- Source: <https://freesound.org/people/UnycodeAdmin/sounds/705348/>
- Changes: Downloaded without a login as Freesound's preview of sound 705348 (705348_15297425-hq.mp3) and checked by SHA-256; the 0.45 s from 0.30 s cut, below 70 Hz and above 9 kHz removed, faded in over 1 ms and out over 80 ms, levelled and encoded as 96 kb/s MP3 by scripts/room-sounds/make.py

### A domino game's pass: two knuckle knocks on a wooden table («knocking.wav»)

- Files: `content/assets/rooms/sounds/knock.mp3`
- Author: Yoshicakes77
- License: [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) (`CC0-1.0`)
- Source: <https://freesound.org/people/Yoshicakes77/sounds/645927/>
- Changes: Downloaded without a login as Freesound's preview of sound 645927 (645927_12983992-hq.mp3) and checked by SHA-256; 300 ms cut from 0.70 s, below 70 Hz and above 9 kHz removed, faded in over 1 ms and out over 50 ms, levelled and encoded as 96 kb/s MP3 by scripts/room-sounds/make.py

### A domino game's hand ending: dominoes shuffled about on a table («Shuffling Dominos»)

- Files: `content/assets/rooms/sounds/hand.mp3`
- Author: RobertWulfman
- License: [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) (`CC0-1.0`)
- Source: <https://freesound.org/people/RobertWulfman/sounds/220839/>
- Changes: Downloaded without a login as Freesound's preview of sound 220839 (220839_3908792-hq.mp3) and checked by SHA-256; 1.2 s cut from 1.77 s, below 70 Hz and above 9 kHz removed, faded in over 50 ms and out over 350 ms, levelled and encoded as 96 kb/s MP3 by scripts/room-sounds/make.py

### The adults' word list: everyday Azerbaijani nouns and adjectives for the word games, starting from the theme packs' words, with the spellings that are correct too; written for Elifba, copied from no dictionary or list

- Files: `content/rooms/words.json`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### The anagram's clues: one short Azerbaijani line per word of the adults' word list, naming its field and one trait without the word; written for Elifba, copied from no dictionary or list

- Files: `content/rooms/clues.json`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://github.com/nijataliyev7/elifba>

### Board backdrops in Fluent Emoji's manner: a meadow by a farm (animals), a prehistoric valley (dinosaurs), an orchard (fruit), a sky with clouds (colours), soft hills at dawn (numbers), moon hills (space), a roadside (vehicles), mountain valleys (nature), a classroom (school)

- Files: `content/assets/animals/cemenlik.svg`, `content/assets/dinosaurs/vadi.svg`, `content/assets/fruit/bag.svg`, `content/assets/colors/goy-uzu.svg`, `content/assets/numbers/tepeler.svg`, `content/assets/space/sema.svg`, `content/assets/vehicles/yol.svg`, `content/assets/nature/daglar.svg`, `content/assets/school/sinif-otaq.svg`
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

### The rooms' taunts in the board's voice, in Azerbaijani

- Files: `content/assets/rooms/taunts/*`
- Author: Elifba contributors
- License: [MIT License](https://opensource.org/license/mit) (`MIT`)
- Source: <https://elevenlabs.io/docs/overview/capabilities/text-to-speech/eleven-v4>
- Changes: A synthetic voice, not a person's recording: made with ElevenLabs Eleven v4 (eleven_v4, language az) in the Voice Library voice «Nigar – Ad Read» under a paid plan, in November 2026, one clip per taunt of content/rooms/taunts.json (our own phrases); trimmed and levelled with ffmpeg
