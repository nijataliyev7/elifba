import { useCallback, useState } from 'react';
import { Countdown, Counted, RoundHead } from '@/components/rooms/live-round';
import { MECHANIC_VIEWS } from '@/components/rooms/mechanics/views';
import { REVEAL_MS } from '@/components/rooms/motion';
import { RaceLanes } from '@/components/rooms/race-lanes';
import { GameStrip, useRivalry } from '@/components/rooms/rivalry-strip';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import { ScreenFit } from '@/components/rooms/screen-fit';
import { Standing } from '@/components/rooms/standing';
import { ScreenTauntReaction } from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import { usePreviousReveal } from '@/components/rooms/use-motion';
import type { RoomMotion } from '@/components/rooms/use-motion';
import { useServerNow } from '@/components/rooms/use-server-now';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { ServerClock } from '@/rooms/clock';
import type { RoomRaceGame, RoomRound, RoomState } from '@/rooms/contract';

/**
 * The current round as every player sees it, without anyone's own answer:
 * the rivalry strip of the first two (ADR 027), the seconds and the bar of
 * the estimated server clock (for show only), the material only while the
 * state carries it and the clock is before the question, the question with
 * the count of answers, then the reveal — the solution beside the plain
 * ranking with two players, beside the race lanes with three or more.
 */
export function ScreenRound({
    state,
    game,
    round,
    clock,
    placeLabels,
    motion,
    icons,
    taunts,
    tauntsHidden,
}: {
    state: RoomState;
    game: RoomRaceGame;
    round: RoomRound;
    clock: ServerClock;
    placeLabels: string[];
    motion: RoomMotion;
    icons: RivalryIcons;
    taunts: TauntList;
    /** This screen hides taunts (its «Atmacalar»). */
    tauntsHidden: boolean;
}) {
    const { t } = useTranslation();
    const now = useServerNow(clock, true);
    const view = MECHANIC_VIEWS[game.mechanic];
    const previous = usePreviousReveal(game, round, state.ranking);
    const rivalry = useRivalry(state, null);
    const race = rivalry.pair?.mode === 'race';
    // A round's study and question share one size (`ScreenFit`): the question's
    // grid takes the place the study's had.
    const shared = round.phase === 'study' || round.phase === 'answer';
    const [fitted, setFitted] = useState<{ round: number; zoom: number }>({
        round: -1,
        zoom: 1,
    });
    const cap = shared && fitted.round === round.index ? fitted.zoom : 1;
    const index = round.index;
    const remember = useCallback(
        (zoom: number) =>
            setFitted((last) =>
                last.round === index && last.zoom <= zoom
                    ? last
                    : { round: index, zoom },
            ),
        [index],
    );
    const fit = shared ? remember : undefined;
    const ranking = {
        delay: REVEAL_MS.ranking,
        motion: motion.phase ? { previous, delay: REVEAL_MS.ranking } : null,
    };

    return (
        <section
            data-phase={round.phase}
            data-round={round.index}
            className={cn(
                'flex min-h-0 flex-1 flex-col',
                rivalry.pair === null ? 'gap-[3vh]' : 'gap-[2vh]',
            )}
        >
            <GameStrip
                rivalry={rivalry}
                state={state}
                placeLabels={placeLabels}
                icons={icons}
                moves={motion.strip}
                big
            />

            <ScreenTauntReaction
                shown={state.taunts?.shown}
                players={state.players}
                taunts={taunts}
                now={now}
                hidden={tauntsHidden}
            />

            <RoundHead
                game={game}
                round={round}
                now={now}
                motion={motion.round}
                big
            />

            <div
                key={`${round.index}:${round.phase}`}
                data-motion={motion.phase ? 'on' : undefined}
                className="flex min-h-0 flex-1 flex-col"
            >
                {round.phase === 'countdown' && (
                    <ScreenFit className="flex-1">
                        <Countdown
                            game={game}
                            until={round.opens_ms}
                            now={now}
                            big
                        />
                    </ScreenFit>
                )}

                {round.phase === 'study' && (
                    <ScreenFit className="flex-1" cap={cap} onFit={fit}>
                        <div className="flex flex-1 flex-col items-center justify-center gap-[4vh] pb-[8vh]">
                            <p className="text-[4vh] font-semibold room-motion:animate-room-enter">
                                {t(`rooms.mechanics.${game.mechanic}.study`)}
                            </p>
                            {round.study !== null && (
                                <view.Study
                                    study={round.study}
                                    covered={
                                        now === null ||
                                        now >= round.answer_from_ms
                                    }
                                    big
                                />
                            )}
                        </div>
                    </ScreenFit>
                )}

                {round.phase === 'answer' && round.ask !== null && (
                    <div className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_auto] items-center gap-[4vw]">
                        <ScreenFit
                            className="h-full justify-center"
                            cap={cap}
                            onFit={fit}
                        >
                            <div className="flex flex-1 flex-col items-center justify-center gap-[4vh]">
                                <p className="text-center text-[4vh] font-semibold room-motion:animate-room-enter">
                                    {t(`rooms.mechanics.${game.mechanic}.ask`)}
                                </p>
                                <view.Ask
                                    ask={round.ask}
                                    picked={[]}
                                    disabled
                                    onPick={() => {}}
                                    big
                                />
                            </div>
                        </ScreenFit>
                        <AnsweredCount
                            count={round.answered_count}
                            total={round.present_count}
                        />
                    </div>
                )}

                {round.phase === 'reveal' &&
                    round.ask !== null &&
                    round.reveal !== null && (
                        <div
                            className={cn(
                                'grid min-h-0 flex-1 gap-[4vw]',
                                race
                                    ? 'grid-cols-[minmax(0,32fr)_minmax(0,68fr)]'
                                    : 'grid-cols-[minmax(0,5fr)_minmax(0,6fr)]',
                            )}
                        >
                            <div
                                className="flex min-h-0 flex-col room-motion:animate-room-enter"
                                style={{
                                    animationDelay: `${REVEAL_MS.solution}ms`,
                                }}
                            >
                                <ScreenFit className="flex-1">
                                    <view.Solution
                                        ask={round.ask}
                                        solution={round.reveal.solution}
                                        mine={null}
                                        word={round.reveal.word}
                                        big
                                    />
                                </ScreenFit>
                            </div>
                            <div
                                className="flex min-h-0 min-w-0 flex-col room-motion:animate-room-enter"
                                style={{
                                    animationDelay: `${ranking.delay}ms`,
                                }}
                            >
                                <ScreenFit className="flex-1">
                                    {race ? (
                                        <RaceLanes
                                            ranking={state.ranking}
                                            players={state.players}
                                            placeLabels={placeLabels}
                                            results={round.reveal.results}
                                            spent={round.reveal.spent}
                                            pair={rivalry.pair}
                                            icons={icons}
                                            motion={ranking.motion}
                                        />
                                    ) : (
                                        <section className="flex min-w-0 flex-col gap-[1.6vh]">
                                            <h2 className="text-[2.8vh] font-semibold">
                                                {t('rooms.game.ranking')}
                                            </h2>
                                            <Standing
                                                ranking={state.ranking}
                                                players={state.players}
                                                me={null}
                                                placeLabels={placeLabels}
                                                results={round.reveal.results}
                                                spent={round.reveal.spent}
                                                motion={ranking.motion}
                                                big
                                            />
                                        </section>
                                    )}
                                </ScreenFit>
                            </div>
                        </div>
                    )}
            </div>
        </section>
    );
}

/**
 * The count of answers in large figures, with a bar: it comes in after the
 * question, its figure pops at each new answer, and its card is outlined
 * once everyone present has answered.
 */
function AnsweredCount({ count, total }: { count: number; total: number }) {
    const { t } = useTranslation();
    const all = total > 0 && count >= total;

    return (
        <div
            className={cn(
                'flex w-[min(36vh,26vw)] flex-col items-center gap-[1.6vh] rounded-2xl border bg-card p-[3vh] transition-colors motion-reduce:transition-none room-motion:animate-room-enter',
                all && 'border-primary',
            )}
            style={{ animationDelay: '200ms' }}
        >
            <span className="text-[2.6vh] text-muted-foreground">
                {t('rooms.screen.answered')}
            </span>
            <span className="text-[8vh] leading-none font-bold whitespace-nowrap tabular-nums">
                <Counted value={count}>
                    {t('rooms.screen.count', { count, total })}
                </Counted>
            </span>
            <div
                aria-hidden
                className="h-[1.2vh] w-full overflow-hidden rounded-full bg-muted"
            >
                <div
                    className="h-full origin-left rounded-full bg-primary transition-transform duration-300 motion-reduce:transition-none"
                    style={{
                        transform: `scaleX(${total === 0 ? 0 : count / total})`,
                    }}
                />
            </div>
        </div>
    );
}
