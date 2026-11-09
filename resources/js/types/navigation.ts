import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    /** A translation key, translated where it is rendered. */
    title: string;
    /** Shown as is instead of the translated title: the teacher's own words, such as a class name. */
    label?: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    /** A translation key, translated where it is rendered. */
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};
