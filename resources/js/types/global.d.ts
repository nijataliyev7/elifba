import type { InstallOffer } from '@/lib/install-banner';
import type { Auth } from '@/types/auth';

declare global {
    interface Window {
        /** Chrome's install offer, kept by the inline script in app.blade.php. */
        elifbaInstall?: InstallOffer;
    }
}

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            locale: string;
            locales: Record<string, string>;
            translations: Record<string, string>;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
