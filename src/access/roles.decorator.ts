import { SetMetadata } from '@nestjs/common';
import { UserRole } from '@prisma/client';
import { PermissionCode } from './permissions';

export const ROLES_KEY = 'karnacab:roles';
export const PERMISSIONS_KEY = 'karnacab:permissions';

export const Roles = (...roles: UserRole[]) => SetMetadata(ROLES_KEY, roles);

export const RequirePermissions = (...permissions: PermissionCode[]) =>
  SetMetadata(PERMISSIONS_KEY, permissions);
