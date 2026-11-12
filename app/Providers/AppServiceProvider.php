<?php

namespace App\Providers;

use App\Domain\Play\Events\GameSessionTransitioned;
use App\Domain\Play\Listeners\BroadcastSessionStatus;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\GuestBoardGuard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRoles();
        $this->configurePlay();

        // `composer dev`: no queue worker, because QUEUE_CONNECTION is `sync`
        // everywhere (docs/architecture.md §10). Reverb registers its own
        // `reverb:start` entry.
        DevCommands::except('queue');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Account roles (docs/architecture.md §1 "Accounts and roles", ADR 010):
     * `model_has_roles.model_type` holds the alias `user`, not a class name.
     */
    protected function configureRoles(): void
    {
        Relation::morphMap(['user' => User::class]);
    }

    /**
     * The guest board's guard, the pairing and round-count limits and the
     * `session.status` broadcast (docs/architecture.md §4).
     */
    protected function configurePlay(): void
    {
        Auth::viaRequest('board', new GuestBoardGuard);

        $who = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        // `/pult` and `/pult/{code}`: 10 a minute per teacher, so a code
        // cannot be guessed by trying.
        RateLimiter::for('pult', static fn (Request $request): Limit => Limit::perMinute(10)->by($who($request)));

        // Opening a guest board (`POST /guest-boards`): 6 a minute per IP.
        RateLimiter::for('guest-boards', static fn (Request $request): Limit => Limit::perMinute(6)->by((string) $request->ip()));

        // A guest board's 2 s poll, per board, apart from the opening limit.
        RateLimiter::for('guest-board', static fn (Request $request): Limit => Limit::perMinute(120)->by($who($request)));

        // The round count under «Başla» builds every round again: 30 a minute per game.
        RateLimiter::for('board-rounds', static function (Request $request): Limit {
            $game = $request->route('game');

            return Limit::perMinute(30)->by('board-rounds:'.($game instanceof GameSession ? $game->id : (is_string($game) ? $game : '')));
        });

        Event::listen(GameSessionTransitioned::class, BroadcastSessionStatus::class);
    }
}
