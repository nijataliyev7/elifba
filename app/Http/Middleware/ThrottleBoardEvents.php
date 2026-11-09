<?php

namespace App\Http\Middleware;

use App\Domain\Play\Models\GameSession;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /games/{game}/sync` takes at most 240 events a minute per game
 * (docs/architecture.md §4). It counts the events in each body, not the
 * requests, so a board cannot get around it with bigger batches. A board
 * over the limit gets 429 with `Retry-After` and keeps its outbox.
 */
final class ThrottleBoardEvents
{
    public const int EVENTS_PER_MINUTE = 240;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $game = $request->route('game');
        $key = 'board-sync:'.($game instanceof GameSession ? $game->id : (is_string($game) ? $game : ''));
        $events = $request->input('events');
        $count = is_array($events) ? max(1, count($events)) : 1;

        if (RateLimiter::attempts($key) + $count > self::EVENTS_PER_MINUTE) {
            return new JsonResponse(['error' => 'too_many_events'], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        RateLimiter::increment($key, 60, $count);

        return $next($request);
    }
}
