import type { ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    /** What else sits on the row, at its end («Şifrəni unutmusunuz?»). */
    children?: ReactNode;
};

/**
 * «Məni xatırla» on the login and registration pages (architecture §7
 * "Remembered login", ADR 017): on when the page opens, with the
 * shared-computer hint under it. The page owns the state, because the same
 * box governs the e-mail form (`remember`) and the Google link
 * (`?remember=1|0`). Inside the form it posts `remember` like any checkbox.
 */
export default function RememberMe({
    checked,
    onCheckedChange,
    children,
}: Props) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-1.5">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <div className="flex items-center gap-3">
                    <Checkbox
                        id="remember"
                        name="remember"
                        value="1"
                        checked={checked}
                        onCheckedChange={(value) =>
                            onCheckedChange(value === true)
                        }
                        aria-describedby="remember-hint"
                    />
                    <Label htmlFor="remember">{t('auth.remember.label')}</Label>
                </div>
                {children}
            </div>
            <p id="remember-hint" className="text-sm text-muted-foreground">
                {t('auth.remember.hint')}
            </p>
        </div>
    );
}
