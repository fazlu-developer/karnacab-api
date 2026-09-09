import { Body, Controller, Get, Param, Patch, Post, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { NotificationsService } from './notifications.service';
import { AnnounceDto, RegisterDeviceDto } from '../support/dto/support.dto';

@ApiTags('notifications')
@Controller('notifications')
export class NotificationsController {
  constructor(private readonly notifications: NotificationsService) {}

  @Get('catalog')
  @ApiOperation({ summary: 'Channels, events, and reusable dispatch architecture' })
  catalog() {
    return this.notifications.catalog();
  }

  @Get()
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  list(@CurrentActor() actor: Actor) {
    return this.notifications.list(actor);
  }

  @Patch(':id/read')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  read(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.notifications.markRead(actor, BigInt(id));
  }

  @Post('devices')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Register an FCM device token. Push is sent only when Firebase is configured.' })
  device(@CurrentActor() actor: Actor, @Body() dto: RegisterDeviceDto) {
    return this.notifications.registerDevice(actor, dto.token, dto.platform);
  }

  @Post('announce')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Admin broadcast to customer, driver, or ops inboxes' })
  announce(@CurrentActor() actor: Actor, @Body() dto: AnnounceDto) {
    return this.notifications.announce(actor, dto);
  }
}
