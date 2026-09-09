import {
  Body,
  Controller,
  Delete,
  Get,
  Param,
  Patch,
  Post,
  UseGuards,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { ExperienceService } from './experience.service';
import { FamilyMemberDto, PreviewCouponDto } from './dto/experience.dto';

@ApiTags('experience')
@Controller('experience')
export class ExperienceController {
  constructor(private readonly experience: ExperienceService) {}

  @Get('catalog')
  @ApiOperation({ summary: 'Customer experience tools and passenger vs booker fields' })
  catalog() {
    return this.experience.catalog();
  }

  @Get()
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  overview(@CurrentActor() actor: Actor) {
    return this.experience.overview(actor);
  }

  @Get('dashboard')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Customer dashboard: profile, rides, wallet, coupons, invoices, family, places' })
  dashboard(@CurrentActor() actor: Actor) {
    return this.experience.overview(actor);
  }

  @Get('coupons')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  coupons(@CurrentActor() actor: Actor) {
    return this.experience.coupons(actor);
  }

  @Post('coupons/preview')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  preview(@CurrentActor() actor: Actor, @Body() dto: PreviewCouponDto) {
    return this.experience.previewCoupon(actor, dto.code, dto.farePaise ?? 0, dto.discountPaise);
  }

  @Get('family')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  family(@CurrentActor() actor: Actor) {
    return this.experience.family(actor);
  }

  @Post('family')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  addFamily(@CurrentActor() actor: Actor, @Body() dto: FamilyMemberDto) {
    return this.experience.addFamily(actor, dto);
  }

  @Delete('family/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  removeFamily(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.experience.removeFamily(actor, BigInt(id));
  }

  @Get('notifications')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  notifications(@CurrentActor() actor: Actor) {
    return this.experience.notifications(actor);
  }

  @Patch('notifications/:id/read')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  read(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.experience.markRead(actor, BigInt(id));
  }
}
