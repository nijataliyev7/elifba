import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenu } from '@/components/user-menu';
import { start } from '@/routes';

/**
 * The teacher shell's header on every viewport (docs/frontend.md §1): the
 * sidebar toggle (`lg+` only: below it there is no sidebar), the brand,
 * which leads home by role (`/start`), and the user menu.
 */
export function AppHeader() {
    const { name } = usePage().props;

    return (
        <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between gap-2 border-b bg-background/95 px-3 backdrop-blur supports-backdrop-filter:bg-background/80 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1 hidden lg:inline-flex" />
                <Link
                    href={start()}
                    className="flex min-w-0 items-center gap-2 rounded-md px-1 transition-opacity hover:opacity-80 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-green-700 text-white">
                        <AppLogoIcon className="size-5 fill-current" />
                    </span>
                    <span className="sr-only truncate text-base font-semibold tracking-tight sm:not-sr-only">
                        {name}
                    </span>
                </Link>
            </div>
            <div className="flex min-w-0 items-center justify-end">
                <UserMenu />
            </div>
        </header>
    );
}
