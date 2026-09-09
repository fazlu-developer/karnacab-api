import {
  Body,
  Controller,
  Get,
  Param,
  Post,
  Query,
  Res,
  UseGuards,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import type { FastifyReply } from 'fastify';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { FranchiseService } from './franchise.service';
import {
  ApplyFranchiseDto,
  CreateFranchiseRenewalDto,
  FranchiseHierarchyQueryDto,
  SignFranchiseAgreementDto,
  UploadFranchiseDocumentDto,
} from './dto/franchise.dto';

const FRANCHISE_ROLES = [
  UserRole.FRANCHISE,
  UserRole.DISTRICT_HEAD,
  UserRole.STATE_HEAD,
  UserRole.ADMIN,
  UserRole.SUPER_ADMIN,
] as const;

@ApiTags('franchise')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Roles(...FRANCHISE_ROLES)
@Controller('franchise')
export class FranchiseController {
  constructor(private readonly franchises: FranchiseService) {}

  @Get('catalog')
  catalog() {
    return this.franchises.catalog();
  }

  @Get('hierarchy')
  @ApiOperation({ summary: 'Corporate → state head → exclusive district seat → fleets → drivers' })
  hierarchy(@CurrentActor() actor: Actor, @Query() query: FranchiseHierarchyQueryDto) {
    return this.franchises.hierarchy(actor, query.districtId);
  }

  @Get()
  @ApiOperation({ summary: 'Franchises owned by the caller' })
  mine(@CurrentActor() actor: Actor) {
    return this.franchises.mine(actor);
  }

  @Post('apply')
  @ApiOperation({ summary: 'Apply with an explicit district territory' })
  apply(@CurrentActor() actor: Actor, @Body() dto: ApplyFranchiseDto) {
    return this.franchises.apply(actor, dto);
  }

  @Get(':id')
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.franchises.one(actor, BigInt(id));
  }

  @Get(':id/territory')
  territory(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.franchises.one(actor, BigInt(id)).then((row) => ({ territory: row.territory, status: row.status }));
  }

  @Post(':id/documents')
  uploadDoc(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: UploadFranchiseDocumentDto,
  ) {
    return this.franchises.uploadDocument(actor, BigInt(id), dto);
  }

  @Get(':id/documents/:documentId/file')
  async docFile(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Param('documentId') documentId: string,
    @Res() reply: FastifyReply,
  ) {
    const file = await this.franchises.documentFile(actor, BigInt(id), BigInt(documentId));
    reply.header('Content-Type', file.mime);
    reply.header('Content-Disposition', `inline; filename="${file.name}"`);
    reply.header('Cache-Control', 'private, no-store');
    return reply.send(file.buffer);
  }

  @Post(':id/agreement')
  sign(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: SignFranchiseAgreementDto) {
    return this.franchises.signAgreement(actor, BigInt(id), dto);
  }

  @Get(':id/fees')
  fees(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.franchises.fees(actor, BigInt(id));
  }

  @Post(':id/fees/:feeId/pay')
  payFee(@CurrentActor() actor: Actor, @Param('id') id: string, @Param('feeId') feeId: string) {
    return this.franchises.payFee(actor, BigInt(id), BigInt(feeId));
  }

  @Get(':id/renewal')
  renewal(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.franchises.renewals(actor, BigInt(id));
  }

  @Post(':id/renewal')
  requestRenewal(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: CreateFranchiseRenewalDto,
  ) {
    return this.franchises.requestRenewal(actor, BigInt(id), dto);
  }

  @Get(':id/revenue')
  revenue(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.franchises.revenue(actor, BigInt(id));
  }

  @Get(':id/commission')
  commission(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.franchises.commission(actor, BigInt(id));
  }

  @Get(':id/performance')
  performance(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.franchises.performance(actor, BigInt(id));
  }
}
