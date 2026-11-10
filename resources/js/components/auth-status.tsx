import { CircleCheck } from 'lucide-react';

/** A message from the server above an auth form (`status`), e.g. a link sent. */
export default function AuthStatus({ message }: { message: string }) {
    return (
        <div
            role="status"
            className="flex items-start gap-2 rounded-md border border-green-200 bg-green-50 px-3 py-2.5 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/50 dark:text-green-300"
        >
            <CircleCheck aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span>{message}</span>
        </div>
    );
}
