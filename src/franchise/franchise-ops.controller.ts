import { Body, Controller, Get, Param, Patch, Post, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { FranchiseService } from './franchise.service';
import {
  CreateFranchiseFeeDto,
  FranchiseLifecycleDto,
  PatchFranchiseTerritoryDto,
} from './dto/franchise.dto';

const STAFF = [UserRole.ADMIN, UserRole.SUPER_ADMIN, UserRole.STATE_HEAD] as const;

@ApiTags('ops')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Roles(...STAFF)
@Controller('ops/franchises')
export class FranchiseOpsController {
  constructor(private readonly franchises: FranchiseService) {}

  @Get()
  @ApiOperation({ summary: 'Franchise applications in admin/state scope' })
  list(@CurrentActor() actor: Actor) {
    return this.franchises.list(actor);
  }

  @Get(':id')
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.franchises.one(actor, BigInt(id));
  }

  @Patch(':id/territory')
  @ApiOperation({ summary: 'Assign an explicit district. Blocked if another ACTIVE exclusive seat exists' })
  territory(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: PatchFranchiseTerritoryDto,
  ) {
    return this.franchises.setTerritory(actor, BigInt(id), dto);
  }

  @Patch(':id/lifecycle')
  @ApiOperation({
    summary:
      'Applied → Under Review → Approved → Active / Suspended / Expired / Terminated. Activating claims the unique district seat.',
  })
  lifecycle(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: FranchiseLifecycleDto) {
    return this.franchises.lifecycle(actor, BigInt(id), dto);
  }

  @Post(':id/fees')
  addFee(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: CreateFranchiseFeeDto) {
    return this.franchises.addFee(actor, BigInt(id), dto);
  }
}
