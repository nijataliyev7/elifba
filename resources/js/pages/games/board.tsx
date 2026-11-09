import { Head } from '@inertiajs/react';
import { BoardScreen } from '@/components/games/board-screen';
import { SESSION_AUTH } from '@/game/http';
import { useTranslation } from '@/hooks/use-translation';
import type { BoardProps } from '@/types';

/**
 * `/games/{game}/board`, the teacher board (docs/architecture.md §4): the
 * teacher's own laptop, with the teacher's session. The guest board
 * (`/lovhe`) draws the same `BoardScreen` with its bearer.
 */
export default function Board(props: BoardProps) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('games.board.title')} />
            <BoardScreen {...props} auth={SESSION_AUTH} />
        </>
    );
}
