import { placeColors } from '@/components/share/place-colors';
import { StudentAvatar } from '@/components/students/student-avatar';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Avatar, PodiumPlace } from '@/types';

type Props = {
    /** Places 1–3 as the server built them; never empty here. */
    podium: PodiumPlace[];
    avatars: Avatar[];
};

/** Columns by how many places someone holds. */
const COLUMNS: Record<number, string> = {
    1: 'md:mx-auto md:w-full md:max-w-md md:grid-cols-1',
    2: 'md:grid-cols-2',
    3: 'md:grid-cols-3',
};

/** On a wide screen the classic order: 2nd, 1st, 3rd. */
const ORDER: Record<number, string> = {
    1: 'md:order-2',
    2: 'md:order-1',
    3: 'md:order-3',
};

/** The steps' heights on a wide screen; on a phone they are bars. */
const STEP: Record<number, string> = {
    1: 'md:min-h-44',
    2: 'md:min-h-32',
    3: 'md:min-h-24',
};

/**
 * The podium for the projector and the phone (architecture §5): every tied
 * student stands on their place, names in Andika, as big as the screen
 * allows. A phone lists the places 1, 2, 3 from the top; a wide screen
 * stands them 2nd, 1st, 3rd on steps in the medal colours.
 */
export function PodiumStage({ podium, avatars }: Props) {
    const { t } = useTranslation();

    return (
        <ol
            className={cn(
                'grid grid-cols-1 gap-6 md:items-end md:gap-4',
                COLUMNS[podium.length],
            )}
        >
            {podium.map((place) => {
                const colors = placeColors(place.place);

                return (
                    <li
                        key={place.place}
                        className={cn(
                            'flex min-w-0 flex-col gap-4',
                            ORDER[place.place],
                        )}
                    >
                        <ul className="flex flex-wrap items-end justify-center gap-x-4 gap-y-3">
                            {place.students.map((student) => (
                                <li
                                    key={student.id}
                                    className="flex max-w-full min-w-0 flex-col items-center gap-1 text-center"
                                >
                                    <StudentAvatar
                                        avatar={student.avatar}
                                        avatars={avatars}
                                        initial={student.display_name.charAt(0)}
                                        className="size-16 text-2xl md:size-20 xl:size-24"
                                    />
                                    <span className="max-w-40 font-board text-lg leading-tight font-bold break-words md:text-xl xl:text-2xl">
                                        {student.display_name}
                                    </span>
                                </li>
                            ))}
                        </ul>

                        <div
                            className={cn(
                                'flex flex-col items-center justify-center gap-1 rounded-xl border-4 border-black/80 px-4 py-3 text-neutral-950 md:rounded-b-none',
                                STEP[place.place],
                            )}
                            style={{ backgroundColor: colors.fill }}
                        >
                            <span
                                className="font-board text-3xl leading-none font-bold xl:text-4xl"
                                aria-label={t('rankings.place', {
                                    place: place.place_label,
                                })}
                            >
                                {place.place_label}
                            </span>
                            <span className="text-sm font-semibold tabular-nums md:text-base">
                                {t('rankings.points', { count: place.points })}
                            </span>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
