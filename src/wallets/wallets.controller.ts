import { Controller, Get, Param, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { RequirePermissions } from '../access/roles.decorator';
import { Permission } from '../access/permissions';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { WalletsService } from './wallets.service';

@ApiTags('wallets')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Controller('wallets')
export class WalletsController {
  constructor(private readonly wallets: WalletsService) {}

  @Get('me')
  @RequirePermissions(Permission.WalletsRead)
  @ApiOperation({ summary: 'Wallets owned by the authenticated user (ledger-backed balance)' })
  mine(@CurrentActor() actor: Actor) {
    return this.wallets.mine(actor.userId, actor.role);
  }

  @Get('commission-policy')
  @RequirePermissions(Permission.WalletsRead)
  @ApiOperation({
    summary: 'Active commission rule for every app. Server-side only; clients must not recalculate.',
  })
  commissionPolicy() {
    return this.wallets.commissionPolicy();
  }

  @Get(':id')
  @RequirePermissions(Permission.WalletsRead)
  @ApiOperation({ summary: 'One wallet if inside authorization scope' })
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.wallets.one(actor, BigInt(id));
  }
}
