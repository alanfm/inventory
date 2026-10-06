interface InventorySessionUser {
  roles?: string[];
  permissions: string[];
  isSuperAdmin?: boolean;
}

/** Mesma autorização efetiva do Gate; o servidor revalida toda operação. */
export function can(
  user: InventorySessionUser | null,
  permission: string,
): boolean {
  return (
    user?.isSuperAdmin === true ||
    (user?.roles?.includes("super-admin") ?? false) ||
    (user?.permissions.includes(permission) ?? false)
  );
}
