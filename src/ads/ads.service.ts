import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { BookingStatus, UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { DomainEvents } from '../common/domain-events.service';
import { Actor } from '../access/territory';
import { LIVE_STATUSES } from '../ride-engine/booking-lifecycle';
import { AdsStorage } from './ads.storage';
import {
  AD_BANNER_MIME,
  AD_CATEGORIES,
  AD_CATEGORY_LABELS,
  AD_ALLOWED_PLACEMENTS,
  AD_BLOCKED_PLACEMENTS,
  AD_STATUSES,
  AD_TYPE_LABELS,
  AD_TYPES,
  adCtr,
  campaignIsLive,
  canServeAds,
  matchesAdLocation,
} from './ad.policy';
import {
  AdEventDto,
  CreateAdCampaignDto,
  PatchAdCampaignDto,
  ReviewAdDto,
  ServeAdsQueryDto,
  UploadAdBannerDto,
} from './dto/ads.dto';

const ACTIVE_BOOKING_STATUSES: BookingStatus[] = [
  BookingStatus.REQUESTED,
  BookingStatus.DRIVER_SEARCHING,
  BookingStatus.ASSIGNED,
  BookingStatus.DRIVER_ASSIGNED,
  BookingStatus.DRIVER_ARRIVING,
  BookingStatus.DRIVER_ARRIVED,
  BookingStatus.ONGOING,
  BookingStatus.STARTED,
  ...LIVE_STATUSES,
];

@Injectable()
export class AdsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly storage: AdsStorage,
    private readonly events: DomainEvents,
  ) {}

  catalog() {
    return {
      categories: AD_CATEGORIES.map((key) => ({ key, title: AD_CATEGORY_LABELS[key] })),
      campaignTypes: AD_TYPES.map((key) => ({ key, title: AD_TYPE_LABELS[key] })),
      statuses: AD_STATUSES,
      placements: {
        allowed: AD_ALLOWED_PLACEMENTS,
        blocked: AD_BLOCKED_PLACEMENTS,
        note: 'Ads must never appear on an active ride, driver navigation, SOS, payment, OTP, or other critical booking actions. Use home, travel, discovery, or catalog only.',
      },
      fields: [
        'business',
        'businessInfo',
        'campaign',
        'banner',
        'campaignType',
        'targetCity',
        'targetDistrict',
        'targetState',
        'startDate',
        'endDate',
        'budget',
        'status',
      ],
      metrics: ['impressions', 'clicks', 'ctr', 'revenuePaise'],
    };
  }

  async create(actor: Actor, dto: CreateAdCampaignDto) {
    this.assertAdvertiser(actor);
    this.assertWindow(dto.startsOn, dto.endsOn);
    await this.assertDistrict(dto.districtId);
    await this.assertState(dto.stateId);
    const row = await this.prisma.adCampaign.create({
      data: {
        advertiserUserId: actor.userId,
        businessName: dto.businessName.trim(),
        businessInfo: dto.businessInfo?.trim() || null,
        title: dto.title.trim(),
        category: dto.category,
        campaignType: dto.campaignType ?? 'banner',
        targetCity: dto.targetCity?.trim() || null,
        stateId: dto.stateId ?? null,
        districtId: dto.districtId ?? null,
        startsOn: new Date(dto.startsOn),
        endsOn: new Date(dto.endsOn),
        budgetPaise: BigInt(dto.budgetRupees * 100),
        ctaUrl: dto.ctaUrl?.trim() || null,
        status: 'pending',
      },
    });
    return this.present(row);
  }

  async list(actor: Actor) {
    const where = actor.unrestricted
      ? {}
      : actor.role === UserRole.ADVERTISER
        ? { advertiserUserId: actor.userId }
        : actor.districtId
          ? { districtId: actor.districtId }
          : { advertiserUserId: actor.userId };
    const rows = await this.prisma.adCampaign.findMany({
      where,
      orderBy: { createdAt: 'desc' },
      take: 100,
      include: { district: { select: { id: true, name: true } }, state: { select: { id: true, name: true } } },
    });
    return { campaigns: rows.map((row) => this.present(row)) };
  }

  async queue(actor: Actor) {
    this.assertAdmin(actor);
    const rows = await this.prisma.adCampaign.findMany({
      where: { status: 'pending' },
      orderBy: { createdAt: 'asc' },
      include: { district: { select: { id: true, name: true } }, state: { select: { id: true, name: true } } },
    });
    return { campaigns: rows.map((row) => this.present(row)) };
  }

  async one(actor: Actor, id: bigint) {
    return this.present(await this.load(actor, id));
  }

  async patch(actor: Actor, id: bigint, dto: PatchAdCampaignDto) {
    const row = await this.load(actor, id);
    if (!this.owns(actor, row) && !actor.unrestricted) {
      throw new ForbiddenException('Cannot edit this campaign');
    }
    if (row.status === 'published' && !actor.unrestricted) {
      throw new BadRequestException('Published campaigns must be paused before editing');
    }
    if (dto.startsOn || dto.endsOn) {
      this.assertWindow(dto.startsOn ?? row.startsOn.toISOString(), dto.endsOn ?? row.endsOn.toISOString());
    }
    if (dto.districtId != null) {
      await this.assertDistrict(dto.districtId);
    }
    if (dto.stateId != null) {
      await this.assertState(dto.stateId);
    }
    if (dto.status === 'pending' && ['published', 'approved'].includes(row.status) && !actor.unrestricted) {
      throw new BadRequestException('Submit a new review after admin decision');
    }
    const updated = await this.prisma.adCampaign.update({
      where: { id },
      data: {
        businessName: dto.businessName?.trim(),
        businessInfo: dto.businessInfo === undefined ? undefined : dto.businessInfo.trim() || null,
        title: dto.title?.trim(),
        category: dto.category,
        campaignType: dto.campaignType,
        targetCity: dto.targetCity === undefined ? undefined : dto.targetCity.trim() || null,
        stateId: dto.stateId,
        districtId: dto.districtId,
        startsOn: dto.startsOn ? new Date(dto.startsOn) : undefined,
        endsOn: dto.endsOn ? new Date(dto.endsOn) : undefined,
        budgetPaise: dto.budgetRupees == null ? undefined : BigInt(dto.budgetRupees * 100),
        ctaUrl: dto.ctaUrl === undefined ? undefined : dto.ctaUrl.trim() || null,
        status: dto.status,
      },
      include: { district: { select: { id: true, name: true } }, state: { select: { id: true, name: true } } },
    });
    return this.present(updated);
  }

  async uploadBanner(actor: Actor, id: bigint, dto: UploadAdBannerDto) {
    const row = await this.load(actor, id);
    if (!this.owns(actor, row) && !actor.unrestricted) {
      throw new ForbiddenException('Cannot upload a banner for this campaign');
    }
    if (!AD_BANNER_MIME.has(dto.mime)) {
      throw new BadRequestException('Upload a JPEG, PNG, or WebP banner');
    }
    const cleaned = dto.fileBase64.replace(/^data:[^;]+;base64,/, '');
    const buffer = Buffer.from(cleaned, 'base64');
    if (buffer.length < 32 || buffer.length > 2 * 1024 * 1024) {
      throw new BadRequestException('Banner must be between 32 bytes and 2 MB');
    }
    const ext = dto.mime === 'image/png' ? 'png' : dto.mime === 'image/webp' ? 'webp' : 'jpg';
    const key = await this.storage.write(id.toString(), buffer, ext);
    const updated = await this.prisma.adCampaign.update({
      where: { id },
      data: { bannerKey: key, bannerMime: dto.mime },
    });
    return this.present(updated);
  }

  async bannerFile(actor: Actor, id: bigint) {
    const row = await this.load(actor, id, true);
    if (!row.bannerKey) {
      throw new NotFoundException('Banner not found');
    }
    return {
      bytes: await this.storage.read(row.bannerKey),
      mime: row.bannerMime ?? 'image/jpeg',
    };
  }

  async review(actor: Actor, id: bigint, dto: ReviewAdDto) {
    this.assertAdmin(actor);
    const row = await this.prisma.adCampaign.findUnique({ where: { id } });
    if (!row) {
      throw new NotFoundException('Campaign not found');
    }
    if (row.status !== 'pending' && row.status !== 'approved') {
      throw new BadRequestException('Only pending campaigns can be reviewed');
    }
    if (dto.status === 'rejected') {
      const updated = await this.prisma.adCampaign.update({
        where: { id },
        data: {
          status: 'rejected',
          rejectedReason: dto.reason?.trim() || 'Rejected by admin',
          reviewedById: actor.userId,
          reviewedAt: new Date(),
        },
      });
      this.events.emit('ads.reviewed', {
        campaignId: updated.id.toString(),
        advertiserUserId: updated.advertiserUserId?.toString() ?? null,
        status: 'rejected',
      });
      return this.present(updated);
    }
    const now = new Date();
    const inWindow = now >= row.startsOn && now <= row.endsOn;
    const funded = Number(row.budgetUsedPaise) < Number(row.budgetPaise);
    const live = inWindow && funded;
    const updated = await this.prisma.adCampaign.update({
      where: { id },
      data: {
        status: live ? 'published' : 'approved',
        rejectedReason: null,
        reviewedById: actor.userId,
        reviewedAt: now,
        publishedAt: live ? now : null,
      },
    });
    this.events.emit('ads.reviewed', {
      campaignId: updated.id.toString(),
      advertiserUserId: updated.advertiserUserId?.toString() ?? null,
      status: updated.status,
    });
    return this.present(updated);
  }

  async pause(actor: Actor, id: bigint) {
    this.assertAdmin(actor);
    const row = await this.prisma.adCampaign.findUnique({ where: { id } });
    if (!row) {
      throw new NotFoundException('Campaign not found');
    }
    if (!['published', 'approved'].includes(row.status)) {
      throw new BadRequestException('Only live or approved campaigns can be paused');
    }
    return this.present(
      await this.prisma.adCampaign.update({
        where: { id },
        data: { status: 'paused' },
      }),
    );
  }

  async resume(actor: Actor, id: bigint) {
    this.assertAdmin(actor);
    const row = await this.prisma.adCampaign.findUnique({ where: { id } });
    if (!row) {
      throw new NotFoundException('Campaign not found');
    }
    if (row.status !== 'paused') {
      throw new BadRequestException('Only paused campaigns can be resumed');
    }
    const now = new Date();
    const live = campaignIsLive({
      status: 'published',
      startsOn: row.startsOn,
      endsOn: row.endsOn,
      budgetPaise: Number(row.budgetPaise),
      budgetUsedPaise: Number(row.budgetUsedPaise),
      now,
    });
    return this.present(
      await this.prisma.adCampaign.update({
        where: { id },
        data: {
          status: live ? 'published' : 'approved',
          publishedAt: live ? row.publishedAt ?? now : row.publishedAt,
        },
      }),
    );
  }

  async serve(actor: Actor, query: ServeAdsQueryDto) {
    if (query.districtId != null && String(query.districtId).trim() !== '') {
      throw new BadRequestException('Client districtId is not accepted. Targeting uses the signed-in district.');
    }
    if (query.city != null && String(query.city).trim() !== '') {
      throw new BadRequestException('Client city is not accepted. Targeting uses the signed-in location.');
    }
    const suppressed = await this.suppressionReason(actor);
    const gate = canServeAds({ placement: query.placement, suppressed });
    if (!gate.ok) {
      return { ads: [], suppressed: gate.reason, placement: query.placement };
    }
    const user = await this.prisma.user.findUnique({
      where: { id: actor.userId },
      select: { districtId: true, stateId: true, lastAddress: true },
    });
    const actorCity = user?.lastAddress ?? null;
    const actorDistrictId = actor.districtId ?? user?.districtId ?? null;
    const actorStateId = actor.stateId ?? user?.stateId ?? null;
    const now = new Date();
    const rows = await this.prisma.adCampaign.findMany({
      where: {
        status: 'published',
        startsOn: { lte: now },
        endsOn: { gte: now },
      },
      include: { district: { select: { id: true, name: true } }, state: { select: { id: true, name: true } } },
      take: 50,
    });
    const ads = rows
      .filter((row) =>
        campaignIsLive({
          status: row.status,
          startsOn: row.startsOn,
          endsOn: row.endsOn,
          budgetPaise: Number(row.budgetPaise),
          budgetUsedPaise: Number(row.budgetUsedPaise),
          now,
        }),
      )
      .filter((row) =>
        matchesAdLocation({
          campaignStateId: row.stateId,
          campaignDistrictId: row.districtId,
          campaignCity: row.targetCity,
          actorStateId,
          actorDistrictId,
          actorCity,
        }),
      )
      .slice(0, 5)
      .map((row) => this.presentCreative(row, query.placement));
    return { ads, suppressed: null, placement: query.placement };
  }

  async track(actor: Actor, id: bigint, kind: 'impression' | 'click', dto: AdEventDto) {
    const gate = canServeAds({
      placement: dto.placement,
      suppressed: await this.suppressionReason(actor),
    });
    if (!gate.ok) {
      throw new ForbiddenException('Ads are not tracked on this screen');
    }
    return this.prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM ad_campaigns WHERE id = ${id} FOR UPDATE`;
      const row = await tx.adCampaign.findUnique({ where: { id } });
      if (!row || !campaignIsLive({
        status: row.status,
        startsOn: row.startsOn,
        endsOn: row.endsOn,
        budgetPaise: Number(row.budgetPaise),
        budgetUsedPaise: Number(row.budgetUsedPaise),
      })) {
        throw new NotFoundException('Campaign is not live');
      }
      const recent = await tx.adEvent.findFirst({
        where: {
          campaignId: id,
          userId: actor.userId,
          kind,
          createdAt: { gte: new Date(Date.now() - 10_000) },
        },
      });
      if (recent) {
        return this.present(row);
      }
      const costKey = kind === 'click' ? 'ad_click_paise' : 'ad_impression_paise';
      const setting = await tx.systemSetting.findUnique({ where: { key: costKey } });
      const cost = Number(setting?.value ?? (kind === 'click' ? 100 : 10)) || 0;
      const used = Number(row.budgetUsedPaise) + cost;
      const exhausted = used >= Number(row.budgetPaise);
      await tx.adEvent.create({
        data: {
          campaignId: id,
          userId: actor.userId,
          kind,
          placement: dto.placement,
        },
      });
      const updated = await tx.adCampaign.update({
        where: { id },
        data: {
          impressions: kind === 'impression' ? { increment: 1 } : undefined,
          clicks: kind === 'click' ? { increment: 1 } : undefined,
          budgetUsedPaise: BigInt(used),
          status: exhausted ? 'completed' : row.status,
        },
      });
      return this.present(updated);
    });
  }

  private async suppressionReason(actor: Actor): Promise<string | null> {
    const since = new Date(Date.now() - 30 * 60 * 1000);
    const [booking, sos, openSos, driver] = await Promise.all([
      this.prisma.booking.findFirst({
        where: {
          OR: [{ customerId: actor.userId }, ...(actor.driverId ? [{ driverId: actor.driverId }] : [])],
          status: { in: [...new Set(ACTIVE_BOOKING_STATUSES)] },
        },
        select: { status: true },
      }),
      this.prisma.platformAuditEvent.findFirst({
        where: { actorUserId: actor.userId, action: 'sos', createdAt: { gte: since } },
        select: { id: true },
      }),
      this.prisma.safetyIncident.findFirst({
        where: {
          reporterUserId: actor.userId,
          type: 'sos',
          status: { in: ['open', 'investigating'] },
          createdAt: { gte: since },
        },
        select: { id: true },
      }),
      actor.driverId
        ? this.prisma.driver.findUnique({
            where: { id: actor.driverId },
            select: { dutyStatus: true },
          })
        : Promise.resolve(null),
    ]);
    if (sos || openSos) {
      return 'sos';
    }
    if (driver?.dutyStatus === 'on_trip') {
      return 'active_trip';
    }
    if (booking) {
      if (booking.status === BookingStatus.DRIVER_ARRIVED) {
        return 'otp';
      }
      if (booking.status === BookingStatus.DRIVER_ARRIVING) {
        return 'driver_arrival';
      }
      if (booking.status === BookingStatus.STARTED || booking.status === BookingStatus.ONGOING) {
        return 'active_trip';
      }
      return 'active_booking';
    }
    return null;
  }

  private async load(actor: Actor, id: bigint, forCreative = false) {
    const row = await this.prisma.adCampaign.findUnique({
      where: { id },
      include: { district: { select: { id: true, name: true } }, state: { select: { id: true, name: true } } },
    });
    if (!row) {
      throw new NotFoundException('Campaign not found');
    }
    if (forCreative && row.status === 'published') {
      return row;
    }
    if (this.owns(actor, row) || actor.unrestricted) {
      return row;
    }
    throw new ForbiddenException('Access denied for this campaign');
  }

  private owns(actor: Actor, row: { advertiserUserId: bigint | null }) {
    return row.advertiserUserId != null && row.advertiserUserId === actor.userId;
  }

  private assertAdvertiser(actor: Actor) {
    if (actor.role !== UserRole.ADVERTISER && !actor.unrestricted) {
      throw new ForbiddenException('Advertiser account required');
    }
  }

  private assertAdmin(actor: Actor) {
    if (!actor.unrestricted) {
      throw new ForbiddenException('Admin approval required');
    }
  }

  private assertWindow(startsOn: string, endsOn: string) {
    const start = new Date(startsOn);
    const end = new Date(endsOn);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) {
      throw new BadRequestException('End date must be after start date');
    }
  }

  private async assertDistrict(districtId?: number) {
    if (districtId == null) {
      return;
    }
    const row = await this.prisma.district.findUnique({ where: { id: districtId } });
    if (!row) {
      throw new BadRequestException('Unknown target district');
    }
  }

  private async assertState(stateId?: number) {
    if (stateId == null) {
      return;
    }
    const row = await this.prisma.state.findUnique({ where: { id: stateId } });
    if (!row) {
      throw new BadRequestException('Unknown target state');
    }
  }

  presentCreative(
    row: Parameters<AdsService['present']>[0],
    placement: string,
  ) {
    const view = this.present(row);
    return {
      id: view.id,
      business: view.business,
      campaign: view.campaign,
      category: view.category,
      campaignType: view.campaignType,
      bannerUrl: view.bannerUrl,
      ctaUrl: view.ctaUrl,
      placement,
      targetCity: view.targetCity,
      targetDistrict: view.targetDistrict,
      targetState: view.targetState,
    };
  }

  present(row: {
    id: bigint;
    businessName: string;
    businessInfo?: string | null;
    title: string;
    category: string;
    campaignType?: string | null;
    bannerKey?: string | null;
    targetCity: string | null;
    stateId?: number | null;
    districtId: number | null;
    state?: { id: number; name: string } | null;
    district?: { id: number; name: string } | null;
    startsOn: Date;
    endsOn: Date;
    budgetPaise: bigint;
    budgetUsedPaise: bigint;
    status: string;
    impressions: number;
    clicks: number;
    ctaUrl: string | null;
    rejectedReason?: string | null;
    publishedAt?: Date | null;
    createdAt: Date;
  }) {
    const impressions = row.impressions;
    const clicks = row.clicks;
    const used = Number(row.budgetUsedPaise);
    return {
      id: row.id.toString(),
      business: row.businessName,
      businessInfo: row.businessInfo ?? null,
      campaign: row.title,
      category: row.category,
      categoryLabel: AD_CATEGORY_LABELS[row.category as keyof typeof AD_CATEGORY_LABELS] ?? row.category,
      campaignType: row.campaignType ?? 'banner',
      campaignTypeLabel: AD_TYPE_LABELS[(row.campaignType ?? 'banner') as keyof typeof AD_TYPE_LABELS] ?? row.campaignType,
      bannerUrl: row.bannerKey ? `/ads/campaigns/${row.id.toString()}/banner` : null,
      targetCity: row.targetCity,
      targetState: row.state
        ? { id: row.state.id, name: row.state.name }
        : row.stateId
          ? { id: row.stateId, name: null }
          : null,
      targetDistrict: row.district
        ? { id: row.district.id, name: row.district.name }
        : row.districtId
          ? { id: row.districtId, name: null }
          : null,
      startDate: row.startsOn.toISOString().slice(0, 10),
      endDate: row.endsOn.toISOString().slice(0, 10),
      budgetPaise: Number(row.budgetPaise),
      budgetRupees: Number(row.budgetPaise) / 100,
      budgetUsedPaise: used,
      budgetUsedRupees: used / 100,
      revenuePaise: used,
      revenueRupees: used / 100,
      status: row.status,
      impressions,
      clicks,
      ctr: adCtr(impressions, clicks),
      ctaUrl: row.ctaUrl,
      rejectedReason: row.rejectedReason ?? null,
      publishedAt: row.publishedAt?.toISOString() ?? null,
      createdAt: row.createdAt.toISOString(),
    };
  }
}
