import InputError from '@/components/input-error';
import { StudentAvatar } from '@/components/students/student-avatar';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Avatar } from '@/types';

type Props = {
    /** Every avatar with its picture, in the file's order. */
    avatars: Avatar[];
    value: string;
    onChange: (avatar: string) => void;
    error?: string;
};

/**
 * A child's avatar, picked with the child (docs/frontend.md §4): big
 * pictures a small finger hits, the chosen one ringed. The choice goes
 * with the form as the hidden `avatar` field.
 */
export function AvatarPicker({ avatars, value, onChange, error }: Props) {
    const { t } = useTranslation();

    return (
        <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium">
                {t('children.fields.avatar')}
            </legend>
            <input type="hidden" name="avatar" value={value} />
            <div className="grid grid-cols-5 gap-2 sm:grid-cols-6">
                {avatars.map(({ key }, number) => (
                    <button
                        key={key}
                        type="button"
                        aria-pressed={key === value}
                        aria-label={t('students.fields.avatar_option', {
                            number: number + 1,
                        })}
                        onClick={() => onChange(key)}
                        className={cn(
                            'flex aspect-square min-h-12 items-center justify-center rounded-2xl border-2 p-1 transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            key === value
                                ? 'border-primary bg-accent'
                                : 'border-transparent hover:bg-accent/60',
                        )}
                    >
                        <StudentAvatar
                            avatar={key}
                            avatars={avatars}
                            className="size-full"
                        />
                    </button>
                ))}
            </div>
            <InputError message={error} />
        </fieldset>
    );
}
