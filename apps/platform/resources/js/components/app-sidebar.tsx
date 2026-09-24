import { Link, usePage } from '@inertiajs/react';
import { Coins, LayoutGrid, Store } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as projectsIndex } from '@/routes/projects';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const page = usePage();
    const dashboardUrl = page.props.currentTeam
        ? dashboard(page.props.currentTeam.slug)
        : '/';

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboardUrl,
            icon: LayoutGrid,
        },
        ...(page.props.currentTeam
            ? [
                  {
                      title: 'Projects',
                      href: projectsIndex(page.props.currentTeam.slug),
                      icon: Store,
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboardUrl} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <TeamSwitcher />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                {page.props.credits !== null ? (
                    <div
                        className="mt-auto flex items-center gap-2 px-2 text-sm text-muted-foreground group-data-[collapsible=icon]:hidden"
                        data-test="credit-balance"
                    >
                        <Coins className="size-4" />
                        <span>
                            <span className="font-medium text-foreground">
                                {page.props.credits}
                            </span>{' '}
                            credits
                        </span>
                    </div>
                ) : null}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
