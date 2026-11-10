import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { navAreas } from '@/components/app/nav-areas';
import AppLogo from '@/components/app-logo';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarRail,
    useSidebar,
} from '@/components/ui/sidebar';
import { useTranslation } from '@/hooks/use-translation';
import { activeArea, activePage } from '@/lib/nav-active';
import type { NavArea } from '@/lib/nav-active';
import { cn } from '@/lib/utils';
import { index as classes } from '@/routes/classes';

/**
 * The teacher shell's sidebar, `lg+` only (docs/frontend.md §1): it collapses
 * to icons and back (the header's toggle, the rail, Ctrl/⌘+B). An area with
 * one page is a link; an area with more is a collapsible group, which
 * collapsed to an icon lists its pages in a flyout menu. Below `lg` the
 * bottom bar and the pill strip navigate instead, so the sidebar's
 * off-canvas drawer is never rendered there.
 */
export function AppSidebar() {
    const { isMobile } = useSidebar();
    const { url } = usePage();
    const current = activeArea(navAreas, url);

    if (isMobile) {
        return null;
    }

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={classes()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup>
                    <SidebarMenu>
                        {navAreas.map((area) =>
                            area.pages.length === 1 ? (
                                <AreaLink
                                    key={area.key}
                                    area={area}
                                    active={area === current}
                                />
                            ) : (
                                <AreaGroup
                                    key={area.key}
                                    area={area}
                                    url={url}
                                    active={area === current}
                                />
                            ),
                        )}
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>

            <SidebarRail />
        </Sidebar>
    );
}

function AreaLink({ area, active }: { area: NavArea; active: boolean }) {
    const { t } = useTranslation();

    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                isActive={active}
                tooltip={{ children: t(area.title) }}
            >
                <Link
                    href={area.pages[0].href}
                    prefetch
                    aria-current={active ? 'page' : undefined}
                >
                    <area.icon />
                    <span>{t(area.title)}</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}

type AreaGroupProps = { area: NavArea; url: string; active: boolean };

function AreaGroup(props: AreaGroupProps) {
    const { state } = useSidebar();

    return state === 'collapsed' ? (
        <AreaFlyout {...props} />
    ) : (
        <AreaAccordion {...props} />
    );
}

/** Expanded: the area's pages under its row, open while one of them is. */
function AreaAccordion({ area, url, active }: AreaGroupProps) {
    const { t } = useTranslation();
    const page = activePage(area, url);
    const [open, setOpen] = useState(active);
    const [wasActive, setWasActive] = useState(active);

    // Opening one of the area's pages from elsewhere (the user menu, a
    // link) opens the group; the teacher can still close it while there.
    if (wasActive !== active) {
        setWasActive(active);

        if (active) {
            setOpen(true);
        }
    }

    return (
        <Collapsible
            asChild
            open={open}
            onOpenChange={setOpen}
            className="group/collapsible"
        >
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        isActive={active && !open}
                        tooltip={{ children: t(area.title) }}
                    >
                        <area.icon />
                        <span>{t(area.title)}</span>
                        <ChevronRight
                            aria-hidden
                            className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                        />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {area.pages.map((item) => (
                            <SidebarMenuSubItem key={item.title}>
                                <SidebarMenuSubButton
                                    asChild
                                    isActive={item === page}
                                >
                                    <Link
                                        href={item.href}
                                        prefetch
                                        aria-current={
                                            item === page ? 'page' : undefined
                                        }
                                    >
                                        <item.icon />
                                        <span>{t(item.title)}</span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

/** Collapsed to icons: the area's icon opens its pages beside the rail. */
function AreaFlyout({ area, url, active }: AreaGroupProps) {
    const { t } = useTranslation();
    const page = activePage(area, url);

    return (
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <SidebarMenuButton
                        isActive={active}
                        className="data-[state=open]:bg-sidebar-accent"
                    >
                        <area.icon />
                        <span>{t(area.title)}</span>
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    side="right"
                    align="start"
                    className="w-56"
                >
                    <DropdownMenuLabel className="flex items-center gap-2">
                        <area.icon
                            aria-hidden
                            className="size-4 text-muted-foreground"
                        />
                        {t(area.title)}
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {area.pages.map((item) => (
                        <DropdownMenuItem key={item.title} asChild>
                            <Link
                                href={item.href}
                                prefetch
                                aria-current={
                                    item === page ? 'page' : undefined
                                }
                                className={cn(
                                    'w-full cursor-pointer',
                                    item === page && 'bg-accent font-medium',
                                )}
                            >
                                <item.icon aria-hidden />
                                {t(item.title)}
                            </Link>
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    );
}
