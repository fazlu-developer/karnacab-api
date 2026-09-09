import {
  Body,
  Controller,
  Get,
  Param,
  Patch,
  Post,
  Query,
  Res,
  UseGuards,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import type { FastifyReply } from 'fastify';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { RequirePermissions, Roles } from '../access/roles.decorator';
import { Permission } from '../access/permissions';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { AdsService } from './ads.service';
import {
  AdEventDto,
  CreateAdCampaignDto,
  PatchAdCampaignDto,
  ReviewAdDto,
  ServeAdsQueryDto,
  UploadAdBannerDto,
} from './dto/ads.dto';

@ApiTags('ads')
@Controller('ads')
export class AdsController {
  constructor(private readonly ads: AdsService) {}

  @Get('catalog')
  @ApiOperation({ summary: 'Advertiser categories, allowed/blocked placements, campaign statuses' })
  catalog() {
    return this.ads.catalog();
  }

  @Get('serve')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({
    summary:
      'Non-intrusive ads for home/travel/discovery/catalog. Empty during an active ride, driver navigation, SOS, payment, OTP, or other critical booking actions. Client district/city is rejected.',
  })
  serve(@CurrentActor() actor: Actor, @Query() query: ServeAdsQueryDto) {
    return this.ads.serve(actor, query);
  }

  @Get('queue')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @ApiOperation({ summary: 'Campaigns waiting for admin approval' })
  queue(@CurrentActor() actor: Actor) {
    return this.ads.queue(actor);
  }

  @Get('campaigns')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.AdvertisingRead)
  @ApiOperation({ summary: 'Campaigns owned by the advertiser (admin sees all)' })
  list(@CurrentActor() actor: Actor) {
    return this.ads.list(actor);
  }

  @Post('campaigns')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.AdvertisingWrite)
  @ApiOperation({ summary: 'Create a campaign. Status is pending until admin approval.' })
  create(@CurrentActor() actor: Actor, @Body() dto: CreateAdCampaignDto) {
    return this.ads.create(actor, dto);
  }

  @Get('campaigns/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.AdvertisingRead)
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.ads.one(actor, BigInt(id));
  }

  @Patch('campaigns/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.AdvertisingWrite)
  patch(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: PatchAdCampaignDto) {
    return this.ads.patch(actor, BigInt(id), dto);
  }

  @Post('campaigns/:id/banner')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.AdvertisingWrite)
  banner(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: UploadAdBannerDto) {
    return this.ads.uploadBanner(actor, BigInt(id), dto);
  }

  @Get('campaigns/:id/banner')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Private banner bytes for a published or owned campaign' })
  async bannerFile(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Res() reply: FastifyReply,
  ) {
    const file = await this.ads.bannerFile(actor, BigInt(id));
    return reply.type(file.mime).send(file.bytes);
  }

  @Post('campaigns/:id/review')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @ApiOperation({ summary: 'Approve or reject. Ads never publish without this.' })
  review(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: ReviewAdDto) {
    return this.ads.review(actor, BigInt(id), dto);
  }

  @Post('campaigns/:id/pause')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  pause(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.ads.pause(actor, BigInt(id));
  }

  @Post('campaigns/:id/resume')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  resume(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.ads.resume(actor, BigInt(id));
  }

  @Post('campaigns/:id/impression')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  impression(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: AdEventDto) {
    return this.ads.track(actor, BigInt(id), 'impression', dto);
  }

  @Post('campaigns/:id/click')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  click(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: AdEventDto) {
    return this.ads.track(actor, BigInt(id), 'click', dto);
  }
}
