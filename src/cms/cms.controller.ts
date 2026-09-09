import { Body, Controller, Get, Param, Patch, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { RequirePermissions, Roles } from '../access/roles.decorator';
import { Permission } from '../access/permissions';
import { CmsService } from './cms.service';
import { PatchCmsPageDto, PatchCmsSiteDto } from './dto/cms.dto';

@ApiTags('cms')
@Controller('cms')
export class CmsController {
  constructor(private readonly cms: CmsService) {}

  @Get('site')
  @ApiOperation({ summary: 'Public website bundle: pages, nav, catalog, FAQs (no dummy accounts)' })
  site() {
    return this.cms.site();
  }

  @Get('pages/:slug')
  @ApiOperation({ summary: 'One published CMS page' })
  page(@Param('slug') slug: string) {
    return this.cms.page(slug);
  }

  @Get('admin/pages')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @RequirePermissions(Permission.PlatformAdmin)
  @ApiOperation({ summary: 'All CMS pages including unpublished' })
  adminList() {
    return this.cms.adminList();
  }

  @Patch('admin/site')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @RequirePermissions(Permission.PlatformAdmin)
  @ApiOperation({ summary: 'Update site chrome, contact and home promo' })
  patchSite(@Body() dto: PatchCmsSiteDto) {
    return this.cms.patchSite(dto);
  }

  @Patch('admin/pages/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @RequirePermissions(Permission.PlatformAdmin)
  @ApiOperation({ summary: 'Update CMS page copy. Do not put fare numbers in body.' })
  patchPage(@Param('id') id: string, @Body() dto: PatchCmsPageDto) {
    return this.cms.patchPage(BigInt(id), dto);
  }
}
