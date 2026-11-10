import { Separator } from '@/components/ui/separator';

/** The quiet line between «Google ilə daxil ol» and the e-mail form. */
export default function AuthDivider({ label }: { label: string }) {
    return (
        <div className="flex items-center gap-3 text-xs text-muted-foreground">
            <Separator className="flex-1" />
            <span className="shrink-0">{label}</span>
            <Separator className="flex-1" />
        </div>
    );
}
