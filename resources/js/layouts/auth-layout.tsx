import { useTranslation } from '@/hooks/use-translation';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

/**
 * Pages pass translation keys as `title` and `description`
 * (`Login.layout = { title: 'auth.login.title', … }`); the layout translates them.
 */
export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <AuthLayoutTemplate
            title={title === '' ? '' : t(title)}
            description={description === '' ? '' : t(description)}
        >
            {children}
        </AuthLayoutTemplate>
    );
}
