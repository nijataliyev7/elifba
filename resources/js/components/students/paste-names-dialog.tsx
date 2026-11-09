import { router, useHttp } from '@inertiajs/react';
import { ArrowLeft, ClipboardPaste, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { preview, store } from '@/routes/classes/students';
import type { PastedName, PastedNameFlag } from '@/types';

type Props = {
    classId: string;
    limits: { paste_length: number; names: number };
    /** Opens the dialog from a larger button (the empty class). */
    size?: 'default' | 'lg';
    /** `outline` beside a page's main action. */
    variant?: 'default' | 'outline';
};

type Row = PastedName & {
    /** The name to add, as the teacher edited it. */
    name: string;
    selected: boolean;
};

/** Flags worth a badge, with the badge's look. */
const BADGES: Partial<
    Record<PastedNameFlag, 'secondary' | 'outline' | 'destructive'>
> = {
    invalid: 'destructive',
    duplicate: 'outline',
    taken: 'destructive',
    in_class: 'secondary',
    initial_added: 'secondary',
    surname_first: 'outline',
};

/**
 * Paste a list of names → see the suggestions → edit and confirm
 * (docs/architecture.md §7). The pasted text holds surnames: it lives only in
 * this component's state, goes to the server only as a POST body for the
 * preview (no URL, no history state, no redirect), and is dropped when the
 * dialog closes. Only the confirmed display names are posted to be stored.
 */
export function PasteNamesDialog({
    classId,
    limits,
    size = 'default',
    variant = 'default',
}: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [rows, setRows] = useState<Row[] | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [adding, setAdding] = useState(false);
    const http = useHttp<{ text: string }, { lines: PastedName[] }>({
        text: '',
    });

    const reset = (): void => {
        http.reset();
        http.clearErrors();
        setRows(null);
        setErrors({});
    };

    const changeOpen = (next: boolean): void => {
        setOpen(next);

        if (!next) {
            reset();
        }
    };

    const showPreview = async (): Promise<void> => {
        try {
            const response = await http.post(preview.url(classId));

            if (!response) {
                return; // validation errors are on `http.errors`
            }

            setErrors({});
            setRows(
                response.lines.map((line) => ({
                    ...line,
                    name: line.display_name ?? '',
                    selected: line.addable,
                })),
            );
        } catch {
            toast.error(t('common.something_went_wrong'));
        }
    };

    const chosen = (rows ?? []).filter(
        (row) => row.selected && row.name.trim() !== '',
    );

    const update = (line: number, change: Partial<Row>): void => {
        setRows((current) =>
            (current ?? []).map((row) =>
                row.line === line ? { ...row, ...change } : row,
            ),
        );
    };

    const add = (): void => {
        if (chosen.length > limits.names) {
            setErrors({
                names: t('students.paste.too_many', { max: limits.names }),
            });

            return;
        }

        router.post(
            store.url(classId),
            { names: chosen.map((row) => row.name.trim()) },
            {
                preserveScroll: true,
                onStart: () => setAdding(true),
                onFinish: () => setAdding(false),
                onSuccess: () => changeOpen(false),
                // `names.{i}` is the i-th chosen row; show it on that row.
                onError: (received) => {
                    const mapped: Record<string, string> = {};

                    for (const [key, message] of Object.entries(received)) {
                        const match = /^names\.(\d+)$/.exec(key);
                        const row = match
                            ? chosen[Number(match[1])]
                            : undefined;

                        mapped[row ? `line.${row.line}` : 'names'] = message;
                    }

                    setErrors(mapped);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>
                <Button size={size} variant={variant} data-test="paste-names">
                    <UserPlus />
                    {t('students.paste.button')}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('students.paste.title')}</DialogTitle>
                    <DialogDescription>
                        {rows === null
                            ? t('students.paste.description')
                            : t('students.paste.review_description')}
                    </DialogDescription>
                </DialogHeader>

                {rows === null ? (
                    <form
                        className="grid gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void showPreview();
                        }}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="pasted-names">
                                {t('students.paste.label')}
                            </Label>
                            <Textarea
                                id="pasted-names"
                                name="text"
                                value={http.data.text}
                                onChange={(event) =>
                                    http.setData('text', event.target.value)
                                }
                                maxLength={limits.paste_length}
                                rows={8}
                                required
                                autoFocus
                                autoComplete="off"
                                spellCheck={false}
                                placeholder={t('students.paste.placeholder')}
                                className="max-h-[50svh] min-h-40"
                                aria-invalid={
                                    http.errors.text ? true : undefined
                                }
                            />
                            <InputError message={http.errors.text} />
                            <p className="text-sm text-muted-foreground">
                                {t('students.paste.privacy_hint')}
                            </p>
                        </div>
                        <DialogFooter>
                            <Button
                                type="submit"
                                disabled={
                                    http.processing ||
                                    http.data.text.trim() === ''
                                }
                                data-test="preview-names"
                            >
                                {http.processing ? (
                                    <Spinner />
                                ) : (
                                    <ClipboardPaste />
                                )}
                                {t('students.paste.preview')}
                            </Button>
                        </DialogFooter>
                    </form>
                ) : (
                    <div className="grid gap-4">
                        {rows.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('students.paste.nothing_found')}
                            </p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {rows.map((row) => (
                                    <PreviewRow
                                        key={row.line}
                                        row={row}
                                        error={errors[`line.${row.line}`]}
                                        onChange={(change) =>
                                            update(row.line, change)
                                        }
                                    />
                                ))}
                            </ul>
                        )}
                        <InputError message={errors.names} />

                        <DialogFooter className="gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    setRows(null);
                                    setErrors({});
                                }}
                            >
                                <ArrowLeft />
                                {t('common.back')}
                            </Button>
                            <Button
                                type="button"
                                onClick={add}
                                disabled={adding || chosen.length === 0}
                                data-test="confirm-names"
                            >
                                {adding && <Spinner />}
                                {t('students.paste.add', {
                                    count: chosen.length,
                                })}
                            </Button>
                        </DialogFooter>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function PreviewRow({
    row,
    error,
    onChange,
}: {
    row: Row;
    error?: string;
    onChange: (change: Partial<Row>) => void;
}) {
    const { t } = useTranslation();
    const id = `pasted-${row.line}`;
    const badges = row.flags.filter((flag) => flag in BADGES);

    return (
        <li className={cn('grid gap-2 p-3', !row.selected && 'bg-muted/40')}>
            <div className="flex min-w-0 items-center gap-3">
                <Checkbox
                    id={`${id}-selected`}
                    checked={row.selected}
                    onCheckedChange={(checked) =>
                        onChange({ selected: checked === true })
                    }
                    aria-label={t('students.paste.include', { line: row.line })}
                />
                <div className="min-w-0 flex-1">
                    <Label htmlFor={id} className="sr-only">
                        {t('students.paste.name_for_line', { line: row.line })}
                    </Label>
                    <Input
                        id={id}
                        value={row.name}
                        onChange={(event) =>
                            onChange({
                                name: event.target.value,
                                selected: event.target.value.trim() !== '',
                            })
                        }
                        maxLength={40}
                        autoComplete="off"
                        spellCheck={false}
                        placeholder={t('students.fields.name_placeholder')}
                        aria-invalid={error ? true : undefined}
                    />
                </div>
            </div>
            <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 pl-7 text-xs text-muted-foreground">
                <span className="min-w-0 truncate">
                    {t('students.paste.from_line', {
                        line: row.line,
                        text: row.text,
                    })}
                </span>
                {badges.map((flag) => (
                    <Badge key={flag} variant={BADGES[flag]}>
                        {t(`students.flags.${flag}`)}
                    </Badge>
                ))}
            </div>
            {error && <InputError message={error} className="pl-7" />}
        </li>
    );
}
