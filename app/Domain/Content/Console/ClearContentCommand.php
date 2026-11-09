<?php

namespace App\Domain\Content\Console;

use App\Domain\Content\Actions\DeleteContentCache;
use Illuminate\Console\Command;

/**
 * Removes the compiled content file; `php artisan optimize:clear` runs it.
 */
final class ClearContentCommand extends Command
{
    protected $signature = 'content:clear';

    protected $description = 'Remove the compiled content file';

    public function handle(DeleteContentCache $delete): int
    {
        $delete->handle();
        $this->components->info('Content cache cleared successfully.');

        return self::SUCCESS;
    }
}
