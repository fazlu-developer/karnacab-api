import {
  Body,
  Controller,
  Get,
  Param,
  Patch,
  Post,
  Res,
  UseGuards,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import type { FastifyReply } from 'fastify';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { RequirePermissions, Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { Permission } from '../access/permissions';
import { KycService } from './kyc.service';
import {
  PatchDriverBankDto,
  PatchDriverProfileDto,
  PatchDriverVehicleDto,
  ReviewDocumentDto,
  ReviewKycDto,
  SubmitKycDto,
  UploadDriverDocumentDto,
} from './dto/kyc.dto';

const OPS = [
  UserRole.FLEET_OWNER,
  UserRole.DISTRICT_HEAD,
  UserRole.STATE_HEAD,
  UserRole.FRANCHISE,
  UserRole.ADMIN,
  UserRole.SUPER_ADMIN,
] as const;

@ApiTags('kyc')
@Controller()
export class KycController {
  constructor(private readonly kyc: KycService) {}

  @Get('kyc/catalog')
  @ApiOperation({ summary: 'Driver KYC document types and Bihar cities' })
  catalog() {
    return this.kyc.catalog();
  }

  @Get('drivers/me/kyc')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.DRIVER)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Current driver KYC application' })
  me(@CurrentActor() actor: Actor) {
    return this.kyc.snapshot(actor.userId);
  }

  @Patch('drivers/me/profile')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.DRIVER)
  @ApiBearerAuth()
  patchProfile(@CurrentActor() actor: Actor, @Body() dto: PatchDriverProfileDto) {
    return this.kyc.patchProfile(actor.userId, dto);
  }

  @Patch('drivers/me/vehicle')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.DRIVER)
  @ApiBearerAuth()
  patchVehicle(@CurrentActor() actor: Actor, @Body() dto: PatchDriverVehicleDto) {
    return this.kyc.patchVehicle(actor.userId, dto);
  }

  @Patch('drivers/me/bank')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.DRIVER)
  @ApiBearerAuth()
  patchBank(@CurrentActor() actor: Actor, @Body() dto: PatchDriverBankDto) {
    return this.kyc.patchBank(actor.userId, dto);
  }

  @Post('drivers/me/documents')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.DRIVER)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Upload or replace a KYC document into private storage' })
  upload(@CurrentActor() actor: Actor, @Body() dto: UploadDriverDocumentDto) {
    return this.kyc.upload(actor.userId, dto);
  }

  @Get('drivers/me/documents/:id/file')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.DRIVER)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Authenticated preview of a private KYC file' })
  async file(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Res() reply: FastifyReply,
  ) {
    const file = await this.kyc.fileForDriver(actor.userId, BigInt(id));
    reply.header('Content-Type', file.mime);
    reply.header('Content-Disposition', `inline; filename="${file.name}"`);
    reply.header('Cache-Control', 'private, no-store');
    return reply.send(file.buffer);
  }

  @Post('drivers/me/kyc/submit')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.DRIVER)
  @ApiBearerAuth()
  submit(@CurrentActor() actor: Actor, @Body() dto: SubmitKycDto) {
    return this.kyc.submit(actor.userId, dto.termsAccepted);
  }

  @Get('ops/kyc')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(...OPS)
  @RequirePermissions(Permission.DriversRead)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Driver KYC queue' })
  queue() {
    return this.kyc.listQueue();
  }

  @Get('ops/kyc/documents/:id/file')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(...OPS)
  @RequirePermissions(Permission.DriversRead)
  @ApiBearerAuth()
  async opsFile(@Param('id') id: string, @Res() reply: FastifyReply) {
    const file = await this.kyc.fileForOps(BigInt(id));
    reply.header('Content-Type', file.mime);
    reply.header('Content-Disposition', `inline; filename="${file.name}"`);
    reply.header('Cache-Control', 'private, no-store');
    return reply.send(file.buffer);
  }

  @Get('ops/kyc/:driverId')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(...OPS)
  @RequirePermissions(Permission.DriversRead)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Full driver KYC profile for ops review' })
  async opsProfile(@Param('driverId') driverId: string) {
    const userId = await this.kyc.opsDriverUserId(BigInt(driverId));
    return this.kyc.snapshot(userId);
  }

  @Patch('ops/kyc/:driverId/documents/:documentId')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(...OPS)
  @RequirePermissions(Permission.DriversWrite)
  @ApiBearerAuth()
  reviewDoc(
    @CurrentActor() actor: Actor,
    @Param('driverId') driverId: string,
    @Param('documentId') documentId: string,
    @Body() dto: ReviewDocumentDto,
  ) {
    return this.kyc.reviewDocument(BigInt(driverId), BigInt(documentId), actor.userId, dto);
  }

  @Patch('ops/kyc/:driverId')
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(...OPS)
  @RequirePermissions(Permission.DriversWrite)
  @ApiBearerAuth()
  reviewApp(@Param('driverId') driverId: string, @Body() dto: ReviewKycDto) {
    return this.kyc.reviewApplication(BigInt(driverId), dto);
  }
}
