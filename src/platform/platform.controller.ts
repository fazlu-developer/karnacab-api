import { Controller, Get, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import {
  PLATFORM_DOMAINS,
  PermissionCode,
  hasPermission,
  permissionsFor,
} from '../access/permissions';

@ApiTags('platform')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard)
@Controller('platform')
export class PlatformController {
  @Get('session')
  @ApiOperation({
    summary: 'Current platform session: role, permissions, territory, and domain map',
  })
  session(@CurrentActor() actor: Actor) {
    const role = actor.role;
    const permissions = permissionsFor(role);
    return {
      userId: actor.userId.toString(),
      role,
      districtId: actor.districtId,
      stateId: actor.stateId,
      unrestricted: actor.unrestricted,
      permissions,
      domains: PLATFORM_DOMAINS.map((domain) => ({
        key: domain,
        canRead: hasPermission(role, `${domain}.read` as PermissionCode),
        canWrite: hasPermission(role, `${domain}.write` as PermissionCode),
      })),
      apiVersion: '1',
    };
  }

  @Get('roles')
  @ApiOperation({ summary: 'Role to permission catalog (authenticated)' })
  roles() {
    return {
      domains: PLATFORM_DOMAINS,
      note: 'Permissions are derived from users.role. Territory is enforced in Nest services, not the UI.',
    };
  }
}
