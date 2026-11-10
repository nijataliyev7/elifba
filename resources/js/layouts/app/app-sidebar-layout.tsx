import { AppContent } from '@/components/app-content';
import { AppHeader } from '@/components/app-header';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AreaPills } from '@/components/area-pills';
import { BottomNav } from '@/components/bottom-nav';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { InstallBanner } from '@/components/install-banner';
import type { AppLayoutProps } from '@/types';

/**
 * The teacher shell (docs/frontend.md §1): the sidebar from `lg`, below it
 * the bottom tab bar and the pill strip; the header on every viewport.
 * Breadcrumbs show when a page has a way back (two or more).
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppHeader />
                <AreaPills />
                {breadcrumbs.length > 1 && (
                    <div className="flex min-h-10 items-center border-b px-4 py-2 md:px-6">
                        <Breadcrumbs breadcrumbs={breadcrumbs} />
                    </div>
                )}
                <div className="flex flex-1 flex-col pb-[calc(4rem+env(safe-area-inset-bottom))] lg:pb-0">
                    {children}
                </div>
                <BottomNav />
                <InstallBanner aboveTabBar />
            </AppContent>
        </AppShell>
    );
}
