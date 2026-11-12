import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Play, Trash2 } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { ChildStars } from '@/components/children/child-stars';
import { EditChildDialog } from '@/components/children/edit-child-dialog';
import { LearnedLetters } from '@/components/classes/learned-letters';
import Heading from '@/components/heading';
import { NameSpellingFix } from '@/components/students/name-spelling-fix';
import { NameVoiceMark } from '@/components/students/name-voice-mark';
import { StudentAvatar } from '@/components/students/student-avatar';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useTranslation } from '@/hooks/use-translation';
import { destroy, index, show, update } from '@/routes/children';
import { create as createGame } from '@/routes/children/games';
import { update as updateLetters } from '@/routes/children/learned-letters';
import type {
    Avatar,
    Child,
    ChildStars as ChildStarsData,
    Letter,
} from '@/types';

type Props = {
    child: Child;
    stars: ChildStarsData;
    alphabet: Letter[];
    avatars: Avatar[];
    retentionDays: number;
};

/**
 * A parent's child (architecture §5 "A child's stars", §7 "Parents and
 * their children", ADR 011), phone first: the avatar and the name with
 * «Oyna» under them, the child's own stars (today, the week, in all, the
 * last seven days — no place, no comparison), the learned letters, and
 * deleting the child for good after a dialog. The name and the avatar are
 * changed in a dialog. The name carries a small speaker when the board
 * says it aloud, or a line under it when Elifba's name list does not have
 * it, and a one-tap fix when the list spells it otherwise («Lale» →
 * «Lalə») (ADR 016 decision 10); none of them while the account says no
 * name.
 */
export default function ChildShow({
    child,
    stars,
    alphabet,
    avatars,
    retentionDays,
}: Props) {
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            { title: 'children.index.title', href: index() },
            {
                title: 'children.index.title',
                label: child.display_name,
                href: show(child.id),
            },
        ],
    });

    return (
        <>
            <Head title={child.display_name} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 md:gap-10 md:p-6">
                <div className="flex flex-col gap-4 rounded-2xl border bg-card p-4 sm:flex-row sm:items-center sm:p-6">
                    <div className="flex min-w-0 flex-1 items-center gap-4">
                        <StudentAvatar
                            avatar={child.avatar}
                            avatars={avatars}
                            initial={child.display_name.charAt(0)}
                            className="size-20 text-3xl"
                        />
                        <div className="min-w-0 flex-1">
                            <div className="flex min-w-0 items-center gap-2">
                                <h1 className="min-w-0 truncate text-3xl font-semibold tracking-tight">
                                    {child.display_name}
                                </h1>
                                {child.has_voice === true && (
                                    <NameVoiceMark className="size-5" />
                                )}
                            </div>
                            {child.has_voice === false && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {t('children.voice.missing')}
                                </p>
                            )}
                            {child.voice_spelling !== null && (
                                <div className="mt-1">
                                    <NameSpellingFix
                                        spelling={child.voice_spelling}
                                        url={update.url(child.id)}
                                        avatar={child.avatar}
                                        large
                                    />
                                </div>
                            )}
                        </div>
                        <EditChildDialog
                            key={`${child.display_name}|${child.avatar}`}
                            child={child}
                            avatars={avatars}
                        />
                    </div>
                    <Button
                        asChild
                        size="lg"
                        className="h-14 px-8 text-lg sm:w-auto"
                    >
                        <Link href={createGame(child.id)} data-test="play">
                            <Play className="size-5" />
                            {t('children.play')}
                        </Link>
                    </Button>
                </div>

                <ChildStars stars={stars} retentionDays={retentionDays} />

                <Separator />

                <LearnedLetters
                    key={child.learned_letters.join('')}
                    url={updateLetters.url(child.id)}
                    alphabet={alphabet}
                    learned={child.learned_letters}
                    description="children.letters.description"
                />

                <Separator />

                <section className="space-y-4" aria-labelledby="child-delete">
                    <div id="child-delete">
                        <Heading
                            variant="small"
                            title={t('children.delete.button')}
                            description={t('children.delete.hint')}
                        />
                    </div>
                    <ConfirmDialog
                        trigger={
                            <Button variant="destructive" className="h-11">
                                <Trash2 />
                                {t('children.delete.button')}
                            </Button>
                        }
                        title={t('children.delete.title')}
                        description={t('children.delete.description')}
                        confirmLabel={t('children.delete.confirm')}
                        form={destroy.form(child.id)}
                        destructive
                    />
                </section>
            </div>
        </>
    );
}
