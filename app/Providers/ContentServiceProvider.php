<?php

namespace App\Providers;

use App\Domain\Content\Console\CacheContentCommand;
use App\Domain\Content\Console\ClearContentCommand;
use App\Domain\Content\Console\ValidateContentCommand;
use App\Domain\Content\Console\WriteCreditsCommand;
use App\Domain\Content\Support\ContentRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Built-in content (docs/architecture.md §3, §8): one ContentRepository per
 * request over `content/`, the `content:*` commands (`content:credits`
 * writes CREDITS.md), and `content:cache` /
 * `content:clear` in `optimize` / `optimize:clear`.
 *
 * Tests never read `bootstrap/cache/content.php`, so a stale compiled file
 * left by a local `optimize` cannot change their results.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContentRepository::class, static fn (Application $app): ContentRepository => new ContentRepository(
            root: $app->basePath('content'),
            compiled: $app->runningUnitTests() ? null : $app->bootstrapPath('cache/content.php'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ValidateContentCommand::class,
                CacheContentCommand::class,
                ClearContentCommand::class,
                WriteCreditsCommand::class,
            ]);
        }

        $this->optimizes(optimize: 'content:cache', clear: 'content:clear');
    }
}
