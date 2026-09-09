import {
  CanActivate,
  ExecutionContext,
  ForbiddenException,
  Injectable,
} from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { UserRole } from '@prisma/client';
import { PermissionCode, hasPermission } from './permissions';
import { PERMISSIONS_KEY, ROLES_KEY } from './roles.decorator';

@Injectable()
export class RolesGuard implements CanActivate {
  constructor(private readonly reflector: Reflector) {}

  canActivate(context: ExecutionContext): boolean {
    const roles =
      this.reflector.getAllAndOverride<UserRole[]>(ROLES_KEY, [
        context.getHandler(),
        context.getClass(),
      ]) ?? [];
    const permissions =
      this.reflector.getAllAndOverride<PermissionCode[]>(PERMISSIONS_KEY, [
        context.getHandler(),
        context.getClass(),
      ]) ?? [];

    if (roles.length === 0 && permissions.length === 0) {
      return true;
    }

    const request = context.switchToHttp().getRequest<{
      user?: { sub: string; role: string };
    }>();
    const role = request.user?.role;
    if (!role) {
      throw new ForbiddenException('Authenticated role is required');
    }

    if (roles.length > 0 && !roles.includes(role as UserRole)) {
      throw new ForbiddenException('This role cannot access that resource');
    }

    const missing = permissions.filter((permission) => !hasPermission(role, permission));
    if (missing.length > 0) {
      throw new ForbiddenException('Missing permission');
    }

    return true;
  }
}
