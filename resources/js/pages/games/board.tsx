import { Head } from '@inertiajs/react';
import { BoardScreen } from '@/components/games/board-screen';
import { SESSION_AUTH } from '@/game/http';
import { useReleaseScreenOnLeave } from '@/hooks/use-fullscreen';
import { useTranslation } from '@/hooks/use-translation';
import type { TeacherBoardProps } from '@/types';

/**
 * `/games/{game}/board`, the teacher board (docs/architecture.md §4): the
 * teacher's own laptop, with the teacher's session; `phoneRemote` (Settings
 * → Oyun) says whether it offers the phone. The guest board (`/lovhe`)
 * draws the same `BoardScreen` with its bearer. Leaving the page (the
 * result's links are Inertia visits) gives the screen back: out of full
 * screen, the orientation unlocked.
 */
export default function Board(props: TeacherBoardProps) {
    const { t } = useTranslation();

    useReleaseScreenOnLeave();

    return (
        <>
            <Head title={t('games.board.title')} />
            <BoardScreen {...props} auth={SESSION_AUTH} />
        </>
    );
}
