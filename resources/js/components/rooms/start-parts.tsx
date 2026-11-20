import { Pencil, Trophy, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { roundsOf } from '@/components/rooms/catalog';
import type { SetupChoice } from '@/components/rooms/catalog';
import { GameDrawing } from '@/components/rooms/game-drawing';
import { RoomConfirm } from '@/components/rooms/room-confirm';
import { Standing } from '@/components/rooms/standing';
import { standingGroups } from '@/components/rooms/standing-groups';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { destroy as remove } from '@/routes/rooms/members';
import type { RoomPlayer, RoomState } from '@/rooms/contract';

/**
 * Whether a value has changed since the page first drew it (docs/frontend.md
 * rule 15 "Games first", "Movement"): the choice's row pops when the game
 * changes, never on the page's first draw nor on a state fetched again.
 */
function useMovedSinceFirst(value: string): boolean {
    const [seen, setSeen] = useState(value);
    const [moved, setMoved] = useState(false);

    if (seen !== value) {
        setSeen(value);
        setMoved(true);
    }

    return moved;
}

/**
 * Back to the top when the page turns from one view to another — a game's
 * final to the start screen, or back — never on its first draw: the new
 * view starts at its title, not where the old one was scrolled to.
 */
export function useTopOnTurn(view: string): void {
    const first = useRef(view);

    useEffect(() => {
        if (view !== first.current) {
            first.current = view;
            window.scrollTo({ top: 0 });
        }
    }, [view]);
}

/** «Orta · 10 raund»: the choice's difficulty and rounds. */
export function useChoiceLine(): (choice: {
    difficulty: number;
    rounds?: number;
}) => string {
    const { t } = useTranslation();

    return (choice) =>
        `${t(`rooms.difficulty.${choice.difficulty}`)} · ${t('rooms.show.rounds', { count: roundsOf(choice) })}`;
}

/**
 * The choice's row (ADR 026 decision 16): the game's drawing and name over
 * «:difficulty · :count raund». For the host one button that opens «Oyun
 * ayarları», with «Dəyiş»; for a player a `bg-muted` card that is not a
 * button, since nobody but the host can change it. It draws the state's
 * choice (or the host's tap not yet in it) and pops when the game changes.
 */
export function ChoiceRow({
    choice,
    onChange,
    className,
}: {
    choice: SetupChoice;
    /** The host's: opens the settings. Without it the row is read-only. */
    onChange?: () => void;
    className?: string;
}) {
    const { t } = useTranslation();
    const line = useChoiceLine();
    const moved = useMovedSinceFirst(choice.mechanic);
    const body = (
        <>
            <GameDrawing
                mechanic={choice.mechanic}
                className={cn(
                    'text-[48px]',
                    onChange === undefined && 'bg-background',
                )}
            />
            <span className="flex min-w-0 flex-1 flex-col gap-0.5 text-left">
                <span className="truncate font-semibold">
                    {t(`rooms.mechanics.${choice.mechanic}.name`)}
                </span>
                <span className="truncate text-sm text-muted-foreground">
                    {line(choice)}
                </span>
            </span>
        </>
    );
    const shell = cn(
        'flex min-h-16 w-full items-center gap-3 rounded-2xl p-2 pr-3',
        moved && 'motion-safe:animate-room-pop',
        className,
    );

    if (onChange === undefined) {
        return (
            <div key={choice.mechanic} className={cn(shell, 'bg-muted')}>
                {body}
            </div>
        );
    }

    return (
        <button
            key={choice.mechanic}
            type="button"
            onClick={onChange}
            className={cn(
                shell,
                'border bg-card transition-colors outline-none hover:bg-accent/60 focus-visible:ring-[3px] focus-visible:ring-ring/50 motion-reduce:transition-none',
            )}
        >
            {body}
            <span className="flex h-9 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-sm font-medium">
                <Pencil aria-hidden className="size-4" />
                {t('rooms.start.change')}
            </span>
        </button>
    );
}

/**
 * The present players as chips (docs/frontend.md rule 15 "Games first"):
 * the viewer's own tinted with «siz»; for the host each other player with
 * a ✕ whose target is 44px and which asks first, naming the player. A
 * chip pops in when its player joins after the page first drew.
 */
export function JoinedChips({
    roomId,
    state,
    removable,
}: {
    roomId: string;
    state: RoomState;
    /** The host's phone: a ✕ on every chip but the host's own. */
    removable: boolean;
}) {
    const { t } = useTranslation();
    const present = state.players.filter((player) => player.present);
    const [first] = useState(() => new Set(present.map((player) => player.id)));

    if (present.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('rooms.start.nobody')}
            </p>
        );
    }

    return (
        <ul className="flex flex-wrap gap-2">
            {present.map((player) => (
                <JoinedChip
                    key={player.id}
                    roomId={roomId}
                    player={player}
                    mine={player.id === state.me.player_id}
                    removable={removable && !player.host}
                    pop={!first.has(player.id)}
                />
            ))}
        </ul>
    );
}

function JoinedChip({
    roomId,
    player,
    mine,
    removable,
    pop,
}: {
    roomId: string;
    player: RoomPlayer;
    mine: boolean;
    removable: boolean;
    pop: boolean;
}) {
    const { t } = useTranslation();
    const name = player.name ?? t('rooms.show.nameless');

    return (
        <li
            className={cn(
                'flex h-10 max-w-full min-w-0 items-center gap-1.5 rounded-full border pl-3.5 text-sm font-medium',
                removable ? 'pr-0' : 'pr-3.5',
                mine ? 'border-transparent bg-accent' : 'bg-card',
                pop && 'motion-safe:animate-room-pop',
            )}
        >
            <span className="min-w-0 truncate">{name}</span>
            {mine && (
                <span className="shrink-0 text-xs font-normal text-muted-foreground">
                    {t('rooms.show.you')}
                </span>
            )}
            {removable && (
                <RoomConfirm
                    trigger={
                        <Button
                            type="button"
                            variant="ghost"
                            className="-my-0.5 size-11 shrink-0 rounded-full text-muted-foreground"
                            aria-label={t('rooms.show.remove_title', { name })}
                        >
                            <X aria-hidden className="size-4" />
                        </Button>
                    }
                    title={t('rooms.show.remove_title', { name })}
                    description={t('rooms.show.remove_description')}
                    confirmLabel={t('rooms.show.remove')}
                    destructive
                    form={remove.form({ room: roomId, member: player.id })}
                />
            )}
        </li>
    );
}

/**
 * The room's standing so far, after a first game (docs/frontend.md rule 15
 * "The start screen"): a quiet `bg-muted` card with «Otağın sıralaması»
 * and the first place, which opens the whole standing in a dialog. Places
 * are the server's, labelled from `place_labels`.
 */
export function StandingCard({
    state,
    placeLabels,
}: {
    state: RoomState;
    placeLabels: string[];
}) {
    const { t } = useTranslation();
    const first = standingGroups(state.ranking, placeLabels)[0];

    if (state.game === null || first === undefined || first.place === null) {
        return null;
    }

    const names = first.rows
        .map(
            (row) =>
                state.players.find((player) => player.id === row.player_id)
                    ?.name ?? t('rooms.show.nameless'),
        )
        .join(', ');

    return (
        <Dialog>
            <DialogTrigger asChild>
                <button
                    type="button"
                    className="flex min-h-11 w-full items-center gap-2.5 rounded-xl bg-muted px-3 py-2 text-left text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <Trophy
                        aria-hidden
                        className="size-4 shrink-0 text-amber-600 dark:text-amber-400"
                    />
                    <span className="shrink-0 font-semibold">
                        {t('rooms.game.standing')}
                    </span>
                    <span className="min-w-0 truncate text-muted-foreground">
                        {t('rooms.start.leader', {
                            place: first.label ?? '',
                            name: names,
                            points: t('rooms.game.points', {
                                count: first.rows[0]?.points ?? 0,
                            }),
                        })}
                    </span>
                </button>
            </DialogTrigger>
            <DialogContent
                aria-describedby={undefined}
                className="max-h-[85dvh] overflow-y-auto motion-reduce:animate-none"
            >
                <DialogTitle>{t('rooms.game.standing')}</DialogTitle>
                <Standing
                    ranking={state.ranking}
                    players={state.players}
                    me={state.me.player_id}
                    placeLabels={placeLabels}
                />
                <DialogClose asChild>
                    <Button type="button" variant="secondary" className="h-11">
                        {t('common.close')}
                    </Button>
                </DialogClose>
            </DialogContent>
        </Dialog>
    );
}
