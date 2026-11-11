export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/**
 * A role an account holds (`App\Enums\Role`, architecture §1 "Accounts and
 * roles"). It names an area; the page translates it and never shows it as is.
 */
export type Role = 'teacher' | 'parent';

export type Auth = {
    user: User;
    /** The roles the account holds; empty before the role choice. */
    roles: Role[];
    /** The area whose navigation the page shows; null before the role choice. */
    area: Role | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
