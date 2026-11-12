<?php

use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\NameVoices;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Language\Support\Az;
use Tests\Support\NameClips;

/*
 * NameVoices (docs/architecture.md §3 "Voice", ADR 016 decision 3): a
 * display name is said only when its first name, without a surname
 * initial, surname or patronymic (`PersonName`), is one word of the
 * built-in list, compared with Azerbaijani
 * casing and never by a near spelling; its clip is a file outside
 * content/assets that no URL names.
 */

function fixtureNameVoices(array $made = ['Əli', 'Hüseyn', 'İlkin', 'Nihat'], array $names = ['Aysel', 'Ayşən', 'Hüseyin', 'Nur', 'Zəhra']): NameVoices
{
    $content = new ContentRepository(NameClips::root($names, $made));

    return new NameVoices($content, new VoiceClips($content->voice()));
}

it('takes the first name before a surname initial', function (string $displayName, string $key): void {
    expect(fixtureNameVoices()->key($displayName))->toBe($key)
        ->and(fixtureNameVoices()->has($displayName))->toBeTrue();
})->with([
    'a name alone' => ['Nihat', 'nihat'],
    'with an initial' => ['Əli M.', 'əli'],
    'another initial, the same clip' => ['Əli H.', 'əli'],
]);

it('takes the first name of a name written with its surname or patronymic, either way round', function (string $displayName): void {
    $voices = fixtureNameVoices();

    expect($voices->key($displayName))->toBe('nihat')
        ->and($voices->file($displayName))->not->toBeNull()->toBe($voices->file('Nihat'));
})->with([
    'surname after' => ['Nihat Əliyev'],
    'surname first, as in a register' => ['Əliyev Nihat'],
    'a -li surname' => ['Nihat Kərimli'],
    'with a patronymic' => ['Nihat Rəşid oğlu'],
    'surname first with a patronymic' => ['Məmmədov Nihat Rəşid oğlu'],
]);

it('says two students of one first name with one clip', function (): void {
    $voices = fixtureNameVoices();

    expect($voices->file('Əli M.'))->not->toBeNull()->toBe($voices->file('Əli H.'))->toBe($voices->file('Əli'));
});

it('leaves a hyphenated or two-word first name silent', function (string $displayName): void {
    expect(fixtureNameVoices()->key($displayName))->toBeNull()
        ->and(fixtureNameVoices()->file($displayName))->toBeNull();
})->with([
    'hyphenated' => ['Ayşən-Nur'],
    'two words' => ['Ayşən Nur'],
    'two words with an initial' => ['Ayşən Nur M.'],
    'a first name of two listed names' => ['Nihat Əli'],
    'a first name of two words with a surname' => ['Ayşən Nur Əliyeva'],
]);

it('matches with Azerbaijani casing', function (): void {
    $voices = fixtureNameVoices();

    expect($voices->key('ƏLİ'))->toBe('əli')
        ->and($voices->key('əli'))->toBe('əli')
        ->and($voices->key('İLKİN'))->toBe('ilkin')
        // I is the capital of ı, so «ILKIN» is «ılkın»: İlkin typed without
        // the Azerbaijani letters, below.
        ->and($voices->key('ILKIN'))->toBe('ilkin')
        ->and($voices->file('ƏLİ'))->toBe($voices->file('Əli'));
});

it('reads a name typed without the Azerbaijani letters as the one listed name it can be', function (string $displayName, string $key): void {
    $voices = fixtureNameVoices();

    expect($voices->key($displayName))->toBe($key)
        ->and($voices->file($displayName))->not->toBeNull()->toBe($voices->file(Az::title($key)));
})->with([
    'without ü' => ['Huseyn', 'hüseyn'],
    'without ə' => ['Eli', 'əli'],
    'without ə, with an initial' => ['Eli M.', 'əli'],
    'without İ, in capitals' => ['ILKIN', 'ilkin'],
    'with a surname' => ['Huseyn Aliyev', 'hüseyn'],
]);

it('says only the exact spelling when two listed names are typed the same without the letters', function (): void {
    $content = new ContentRepository(NameClips::root([], ['Şəmil', 'Şemil', 'Lalə']));
    $voices = new NameVoices($content, new VoiceClips($content->voice()));

    expect($voices->key('Şəmil'))->toBe('şəmil')
        ->and($voices->key('Şemil'))->toBe('şemil')
        ->and($voices->key('Semil'))->toBeNull()
        ->and($voices->key('Lale'))->toBe('lalə');
});

it('gives the list spelling of a first name typed otherwise, for the pages\' fix', function (): void {
    $voices = fixtureNameVoices();

    expect($voices->corrected('Eli'))->toBe('Əli')
        ->and($voices->corrected('Eli M.'))->toBe('Əli M.')
        ->and($voices->corrected('ƏLİ'))->toBe('Əli')
        ->and($voices->corrected('Huseyn'))->toBe('Hüseyn')
        // Spelled so already, off the list, or more than a first name and an initial.
        ->and($voices->corrected('Əli'))->toBeNull()
        ->and($voices->corrected('Əli M.'))->toBeNull()
        ->and($voices->corrected('Ramin'))->toBeNull()
        ->and($voices->corrected('Eli Məmmədov'))->toBeNull()
        ->and($voices->corrected('Ayşən Nur'))->toBeNull();
});

it('never matches a near spelling', function (string $displayName): void {
    expect(fixtureNameVoices()->key($displayName))->toBeNull()
        ->and(fixtureNameVoices()->has($displayName))->toBeFalse();
})->with([
    'a for ə, which is another letter' => ['Ali'],
    'another letter' => ['Nihad'],
    'a letter more' => ['Nihatt'],
    'a part of a name' => ['Nih'],
    'off the list' => ['Ramin'],
]);

it('lists a name with no clip yet, and says it only once the clip is made', function (): void {
    $voices = fixtureNameVoices();

    expect($voices->key('Zəhra'))->toBe('zəhra')
        ->and($voices->has('Zəhra'))->toBeFalse()
        ->and($voices->file('Zəhra'))->toBeNull()
        // Hüseyn and Hüseyin are two entries, each with its own clip.
        ->and($voices->has('Hüseyn'))->toBeTrue()
        ->and($voices->has('Hüseyin'))->toBeFalse();
});

it('hashes a name as every clip: the list spelling, the names tag, the voice', function (): void {
    $content = new ContentRepository(NameClips::root(['Nihat'], []));
    $clips = new VoiceClips($content->voice());
    $line = (new NameVoices($content, $clips))->line('Nihat');

    expect($line->group)->toBe(VoiceLineData::NAME)
        ->and($line->phrase)->toBe('Nihat!')
        ->and($line->text)->toBe('[excited] Nihat!')
        ->and($line->hash)->toBe($clips->hash('[excited] Nihat!'))
        ->and((new NameVoices($content, $clips))->needed())->toEqual([$line->hash => $line]);
});

it('never builds a public URL for a name', function (): void {
    $voices = fixtureNameVoices();
    $file = $voices->file('Nihat');
    $line = $voices->line('Nihat');
    $content = new ContentRepository(dirname((string) $file, 2));

    expect($file)->toEndWith('/content/'.NameVoices::FOLDER.'/'.$line->hash.'.mp3')
        ->not->toContain('/assets/')
        ->and((new VoiceClips($content->voice()))->url($line))->toBeNull()
        ->and(get_class_methods(NameVoices::class))->not->toContain('url');
});
