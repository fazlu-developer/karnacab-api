import { BadRequestException, Injectable } from '@nestjs/common';
import { LedgerDirection, WalletOwnerType } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { KycService } from '../kyc/kyc.service';
import { WalletsService } from '../wallets/wallets.service';
import { Actor } from '../access/territory';
import { SafetyService } from '../safety/safety.service';
import { SupportService } from '../support/support.service';
import { NotificationsService } from '../notify/notifications.service';
import { alertsForDocument } from './driver-document-alerts';
import {
  incentivePeriodKey,
  incentiveProgress,
  parseIncentiveCatalog,
  periodStart,
} from './driver-incentives';
import { DriverSosDto, DriverSupportTicketDto } from './dto/driver-care.dto';

@Injectable()
export class DriverCareService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly kyc: KycService,
    private readonly wallets: WalletsService,
    private readonly safety: SafetyService,
    private readonly supportDesk: SupportService,
    private readonly inbox: NotificationsService,
  ) {}

  async documents(userId: bigint) {
    const snap = await this.kyc.snapshot(userId);
    const documents = (snap.documents as Array<{
      type: string;
      label: string;
      status: string;
      expiresAt: string | null;
      rejectionReason: string | null;
    }>).map((doc) => {
      const alerts = alertsForDocument(doc);
      return { ...doc, alerts, alertWindow: alerts[0]?.window ?? null };
    });
    for (const doc of documents) {
      if (!doc.alertWindow) {
        continue;
      }
      await this.inbox.notifyUnlessRecent({
        userId,
        title: 'Document expiry',
        body: `${doc.label} needs attention (${doc.alertWindow}).`,
        kind: 'document_expiry',
        entity: { type: 'document', id: doc.type },
      });
    }
    return {
      documents,
      alerts: documents.flatMap((row) => row.alerts),
    };
  }

  async incentives(userId: bigint) {
    const driver = await this.prisma.driver.findUnique({ where: { userId } });
    if (!driver) {
      return { incentives: [] };
    }
    const catalogRow = await this.prisma.systemSetting.findUnique({ where: { key: 'driver_incentives' } });
    const catalog = parseIncentiveCatalog(catalogRow?.value);
    const items = [];
    for (const row of catalog) {
      const from = periodStart(row.period);
      const [trips, earnings, award] = await Promise.all([
        this.prisma.booking.count({
          where: { driverId: driver.id, status: 'COMPLETED', updatedAt: { gte: from } },
        }),
        this.prisma.walletLedger.aggregate({
          where: {
            createdAt: { gte: from },
            kind: 'trip',
            direction: LedgerDirection.CREDIT,
            wallet: { ownerType: WalletOwnerType.DRIVER, ownerUserId: userId },
          },
          _sum: { amountPaise: true },
        }),
        this.prisma.driverIncentiveAward.findUnique({
          where: {
            driverId_incentiveId_periodKey: {
              driverId: driver.id,
              incentiveId: row.id,
              periodKey: incentivePeriodKey(row.period),
            },
          },
        }),
      ]);
      const earningsPaise = Number(earnings._sum.amountPaise ?? 0);
      let earnedPaise = award ? Number(award.amountPaise) : 0;
      const view = incentiveProgress({
        row,
        trips,
        earningsPaise,
        earnedPaise,
      });
      if (view.complete && view.valid && !award && row.bonusPaise > 0) {
        earnedPaise = await this.award(driver.id, userId, row.id, incentivePeriodKey(row.period), row.bonusPaise);
      }
      items.push({
        ...view,
        earnedPaise: award ? Number(award.amountPaise) : earnedPaise,
        earnedRupees: (award ? Number(award.amountPaise) : earnedPaise) / 100,
        awarded: Boolean(award) || earnedPaise > 0,
      });
    }
    return { incentives: items };
  }

  async ratings(userId: bigint) {
    const driver = await this.prisma.driver.findUnique({ where: { userId } });
    if (!driver) {
      return { average: 0, count: 0, ratings: [] };
    }
    const rows = await this.prisma.bookingRating.findMany({
      where: { fromRole: 'CUSTOMER', booking: { driverId: driver.id } },
      orderBy: { createdAt: 'desc' },
      take: 40,
      include: { booking: { select: { publicRef: true, pickupText: true, dropText: true, updatedAt: true } } },
    });
    const count = rows.length;
    const average = count ? Math.round((rows.reduce((sum, row) => sum + row.stars, 0) / count) * 10) / 10 : 0;
    return {
      average,
      count,
      ratings: rows.map((row) => ({
        id: row.id.toString(),
        stars: row.stars,
        comment: row.comment,
        bookingId: row.bookingId.toString(),
        publicRef: row.booking.publicRef,
        pickupText: row.booking.pickupText,
        dropText: row.booking.dropText,
        createdAt: row.createdAt.toISOString(),
      })),
    };
  }

  async support(userId: bigint) {
    const [phone, chat, faqSetting, actorUser] = await Promise.all([
      this.setting('driver_support_phone', '08041234500'),
      this.setting('driver_support_chat_url', ''),
      this.setting('driver_faq', '[]'),
      this.prisma.user.findUnique({ where: { id: userId } }),
    ]);
    const fromDb = await this.supportDesk.faqs('driver');
    let faqs = fromDb.faqs;
    if (!faqs.length) {
      try {
        faqs = (JSON.parse(faqSetting) as Array<{ q: string; a: string }>).map((row, index) => ({
          id: String(index),
          question: row.q,
          answer: row.a,
          q: row.q,
          a: row.a,
        }));
      } catch {
        faqs = [];
      }
    }
    const tickets = actorUser
      ? await this.supportDesk.listMine({
          userId,
          role: actorUser.role,
          status: actorUser.status,
          districtId: actorUser.districtId,
          stateId: actorUser.stateId,
        unrestricted: false,
        driverId: null,
        fleetOwnerId: null,
      })
      : { tickets: [] };
    return {
      phone,
      tel: `tel:${phone.replace(/\D/g, '')}`,
      chatUrl: chat || null,
      chatAvailable: Boolean(chat),
      faqs,
      tickets: tickets.tickets.map((row) => ({
        id: row.id,
        status: row.status,
        message: row.message,
        createdAt: row.createdAt,
      })),
    };
  }

  async createTicket(userId: bigint, dto: DriverSupportTicketDto) {
    const driver = await this.prisma.driver.findUnique({
      where: { userId },
      include: { user: true },
    });
    if (!driver) {
      throw new BadRequestException('Driver profile required');
    }
    const ticket = await this.supportDesk.create(
      {
        userId,
        role: driver.user.role,
        status: driver.user.status,
        districtId: driver.user.districtId,
        stateId: driver.user.stateId,
        unrestricted: false,
        driverId: driver.id,
        fleetOwnerId: null,
      },
      {
        subject: 'Driver support',
        message: dto.message,
        kind: 'driver',
        bookingId: dto.bookingId,
      },
    );
    return { id: ticket.id, status: ticket.status, publicRef: ticket.publicRef };
  }

  async sos(actor: Actor, dto?: DriverSosDto) {
    return this.safety.sos(actor, dto);
  }

  private async award(
    driverId: bigint,
    userId: bigint,
    incentiveId: string,
    periodKey: string,
    amountPaise: number,
  ) {
    try {
      const posted = await this.wallets.post({
        ownerType: WalletOwnerType.DRIVER,
        ownerUserId: userId,
        direction: LedgerDirection.CREDIT,
        amountPaise,
        commissionPaise: 0,
        grossPaise: amountPaise,
        kind: 'incentive',
        note: `Incentive ${incentiveId} ${periodKey}`,
      });
      await this.prisma.driverIncentiveAward.create({
        data: {
          driverId,
          incentiveId,
          periodKey,
          amountPaise: BigInt(amountPaise),
          ledgerId: posted.ledger.id,
        },
      });
      await this.inbox.notify({
        userId,
        title: 'Incentive credited',
        body: `₹${amountPaise / 100} was added to your wallet.`,
        kind: 'incentives',
        entity: { type: 'incentive', id: `${incentiveId}:${periodKey}` },
      });
      return amountPaise;
    } catch {
      return 0;
    }
  }

  private async setting(key: string, fallback: string) {
    const row = await this.prisma.systemSetting.findUnique({ where: { key } });
    return row?.value?.trim() || fallback;
  }
}
