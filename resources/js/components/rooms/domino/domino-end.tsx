import { CircleStop, DropletOff, Trophy } from 'lucide-react';
import { gameSummary } from '@/components/rooms/catalog';
import { computerOf } from '@/components/rooms/computer';
import { DominoTable } from '@/components/rooms/domino/domino-table';
import { HandSheet } from '@/components/rooms/domino/hand-sheet';
import { dominoSeatName } from '@/components/rooms/domino/names';
import { viewerSide } from '@/components/rooms/domino/sides';
import { DominoDefs } from '@/components/rooms/domino/tile';
import { EndHead } from '@/components/rooms/end-head';
import { GameFinal } from '@/components/rooms/final-standing';
import { StandingCard } from '@/components/rooms/start-parts';
import type { RoomMotion } from '@/components/rooms/use-motion';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomDomino, RoomDominoGame, RoomState } from '@/rooms/contract';

type Over = Extract<RoomDomino, { phase: 'over' }>;

/**
 * A domino game's end (docs/frontend.md rule 15 "Domino" → "The end", ADR
 * 038 decision 50): under «Oyun bitdi» who won — «Siz qalib gəldiniz» on
 * the winning side's phones, «:names qalib gəldi» elsewhere, the computer
 * named as «Kompüter (Orta)» — the score of the balances («101 : 43») and,
 * after a dry win, «Quru qələbə» with an icon; then the final of the
 * game's own places (`finalRows()`, `FinalStanding`) with its confetti,
 * hits, roll, fanfare and cheer as after any game; then the last hand's
 * sheet, the table as it ended and the room's standing as the plain card.
 * A game the host stopped says «Oyun dayandırıldı» and has no final.
 * `big` is the screen's.
 */
export function DominoEnd({
    state,
    game,
    domino,
    placeLabels,
    motion,
    big = false,
}: {
    state: RoomState;
    game: RoomDominoGame;
    domino: Over;
    placeLabels: string[];
    motion: RoomMotion;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const result = domino.result;
    const stopped = result.ending === 'stopped';
    const level = computerOf(game);
    const viewer = big ? null : state.me.player_id;
    const winner = domino.sides.find((side) => side.side === result.winner);
    const mine = viewerSide(domino, viewer);
    const names =
        winner === undefined
            ? ''
            : winner.seats
                  .map((seat) =>
                      dominoSeatName(
                          t,
                          state.players,
                          domino,
                          level,
                          seat,
                          winner.seats.length > 1,
                      ),
                  )
                  .join(' · ');
    const heading = stopped
        ? t('rooms.domino.stopped')
        : mine !== undefined &&
            winner !== undefined &&
            mine.side === winner.side
          ? t('rooms.domino.won_you')
          : t('rooms.domino.won', { names });
    const score = domino.sides.map((side) => side.balance).join(' : ');

    const head = (
        <EndHead
            icon={stopped ? CircleStop : Trophy}
            muted={stopped}
            lines={[
                { text: `${t('rooms.game.over')} · ${gameSummary(t, game)}` },
                { text: heading, strong: true },
                ...(stopped ? [] : [{ text: score }]),
            ]}
            big={big}
        />
    );

    const dry = result.dry && (
        <p
            data-dry
            className={cn(
                'flex items-center gap-1.5 font-semibold',
                big ? 'text-[max(1rem,2.6vh)]' : 'text-sm',
            )}
        >
            <DropletOff
                aria-hidden
                className={big ? 'size-[2.6vh]' : 'size-4'}
            />
            {t('rooms.domino.dry')}
        </p>
    );

    const final = !stopped && (
        <GameFinal
            state={state}
            placeLabels={placeLabels}
            animate={motion.phase}
            big={big}
        />
    );

    const sheet = domino.sheet !== null && (
        <HandSheet
            domino={domino}
            target={game.target}
            players={state.players}
            computer={level}
            viewer={viewer}
            now={null}
            big={big}
            still
        />
    );

    const table = (
        <DominoTable
            domino={domino}
            table={game.table}
            players={state.players}
            computer={level}
            viewer={viewer}
            now={null}
            big={big}
            still
            className={big ? 'h-[44vh]' : 'h-64'}
        />
    );

    if (big) {
        return (
            <div
                data-motion={motion.phase ? 'on' : undefined}
                data-domino-end
                className="grid min-h-0 flex-1 grid-cols-[minmax(0,3fr)_minmax(0,2fr)] gap-[3vw]"
            >
                <DominoDefs />
                <div className="flex min-w-0 flex-col gap-[2.4vh]">
                    {head}
                    {dry}
                    {final}
                    <div className="mt-auto text-[max(0.875rem,1.8vh)]">
                        <StandingCard state={state} placeLabels={placeLabels} />
                    </div>
                </div>
                <div className="flex min-w-0 flex-col gap-[2.4vh]">
                    {table}
                    {sheet}
                </div>
            </div>
        );
    }

    return (
        <section
            key={game.id}
            data-motion={motion.phase ? 'on' : undefined}
            data-domino-end
            className="flex flex-col gap-3"
        >
            <DominoDefs />
            {head}
            {dry}
            {final}
            {sheet}
            {table}
            <StandingCard state={state} placeLabels={placeLabels} />
        </section>
    );
}
