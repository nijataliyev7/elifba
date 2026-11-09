<?php

use App\Domain\Content\Actions\WriteCredits;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\CreditsDocument;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Tests\Support\ContentFixture;

/*
 * content:validate, content:cache, content:clear and content:credits
 * (docs/architecture.md §3, §8). The cache is written to a temporary path,
 * never to the real bootstrap/cache/content.php, and CREDITS.md beside a
 * fixture root, never over the real one.
 */

function compiledContentPath(): string
{
    return sys_get_temp_dir().'/elifba-content-'.Str::lower(Str::random(10)).'.php';
}

it('validates the real content', function (): void {
    $this->artisan('content:validate')
        ->expectsOutputToContain('Content is valid.')
        ->assertSuccessful();
});

it('prints every problem and fails on broken content', function (): void {
    $this->app->instance(ContentRepository::class, new ContentRepository(ContentFixture::make([
        'file' => 'packs/sample.json',
        'set' => ['words.1.text' => 'ATA', 'version' => 0],
    ])));

    expect(Artisan::call('content:validate'))->toBe(1)
        ->and(Artisan::output())
        ->toContain('2 content problem(s)')
        ->toContain('packs/sample.json: `version` must be a whole number')
        ->toContain('packs/sample.json: words[1] "ATA" must be lowercase');
});

it('compiles the content, then reads only the compiled file, until it is cleared', function (): void {
    $compiled = compiledContentPath();
    $this->app->instance(ContentRepository::class, new ContentRepository(base_path('content'), $compiled));

    $this->artisan('content:cache')->expectsOutputToContain('Content cached successfully.')->assertSuccessful();

    // no JSON files at this root: everything comes from the compiled file
    $cached = new ContentRepository(sys_get_temp_dir().'/elifba-no-content-here', $compiled);
    $files = new ContentRepository(base_path('content'));

    expect($cached->readsCompiled())->toBeTrue()
        ->and($cached->packs())->toEqual($files->packs())
        ->and($cached->levels())->toEqual($files->levels())
        ->and($cached->prompts())->toEqual($files->prompts())
        ->and($cached->credits())->toEqual($files->credits());

    $this->artisan('content:clear')->expectsOutputToContain('Content cache cleared successfully.')->assertSuccessful();

    expect(is_file($compiled))->toBeFalse()
        ->and($cached->readsCompiled())->toBeFalse();
});

it('compiles the JSON files, not an older compiled file', function (): void {
    $compiled = compiledContentPath();
    file_put_contents($compiled, '<?php return ["levels" => [], "prompts" => [], "credits" => [], "packs" => []];');
    $this->app->instance(ContentRepository::class, new ContentRepository(base_path('content'), $compiled));

    $this->artisan('content:cache')->assertSuccessful();

    expect(array_keys((new ContentRepository('/nowhere', $compiled))->packs()))->toContain('fruit');

    unlink($compiled);
});

it('never compiles invalid content', function (): void {
    $compiled = compiledContentPath();
    $this->app->instance(ContentRepository::class, new ContentRepository(ContentFixture::make([
        'file' => 'packs/sample.json',
        'set' => ['style.item' => 'kite'],
    ]), $compiled));

    $this->artisan('content:cache')
        ->expectsOutputToContain('Content was not cached')
        ->expectsOutputToContain('style.item must be one of')
        ->assertFailed();

    expect(is_file($compiled))->toBeFalse();
});

it('runs with optimize and optimize:clear', function (): void {
    expect(ServiceProvider::$optimizeCommands)->toHaveKey('content', 'content:cache')
        ->and(ServiceProvider::$optimizeClearCommands)->toHaveKey('content', 'content:clear');
});

it('serves content assets at /content through the storage:link links', function (): void {
    expect(config('filesystems.links'))->toHaveKey(public_path('content'), base_path('content/assets'));
});

it('writes CREDITS.md beside the content root from its credits.json', function (): void {
    $root = ContentFixture::make();
    $this->app->instance(ContentRepository::class, new ContentRepository($root));

    $this->artisan('content:credits')->expectsOutputToContain('CREDITS.md written.')->assertSuccessful();

    expect(file_get_contents(WriteCredits::path($root)))
        ->toBe(CreditsDocument::render((new ContentRepository($root))->credits()))
        ->toContain('### Sample avatars')
        ->toContain('- Changes: Drawn as plain shapes for the tests.');
});

it('never writes CREDITS.md from invalid content', function (): void {
    $root = ContentFixture::make(['file' => 'credits.json', 'set' => ['credits.0.license' => 'CC-BY-NC-4.0']]);
    $this->app->instance(ContentRepository::class, new ContentRepository($root));

    $this->artisan('content:credits')
        ->expectsOutputToContain('CREDITS.md was not written')
        ->assertFailed();

    expect(is_file(WriteCredits::path($root)))->toBeFalse();
});
