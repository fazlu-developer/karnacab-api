import { ForbiddenException, Injectable, NotFoundException } from '@nestjs/common';
import { UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { Actor } from '../access/territory';
import { SmsPort } from '../common/sms.port';
import { FcmAdapter } from './fcm.adapter';
import { EmailAdapter } from './email.adapter';
import {
  CUSTOMER_NOTIFY_KINDS,
  DRIVER_NOTIFY_KINDS,
  NOTIFY_CHANNELS,
  NOTIFY_EVENTS,
  OPS_NOTIFY_KINDS,
} from './notify.catalog';
import { fillTemplate, templateFor } from './notify.events';

export type NotifyInput = {
  userId: bigint;
  title: string;
  body: string;
  kind: string;
  entity?: { type: string; id: string };
};

export type DispatchInput = {
  event: string;
  userId?: bigint;
  phone?: string;
  email?: string;
  vars?: Record<string, string | number | undefined | null>;
  title?: string;
  body?: string;
  entity?: { type: string; id: string };
  channels?: string[];
};

@Injectable()
export class NotificationsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly fcm: FcmAdapter,
    private readonly sms: SmsPort,
    private readonly email: EmailAdapter,
  ) {}

  catalog() {
    return {
      channels: [...NOTIFY_CHANNELS],
      events: [...NOTIFY_EVENTS],
      customer: [...CUSTOMER_NOTIFY_KINDS],
      driver: [...DRIVER_NOTIFY_KINDS],
      operations: [...OPS_NOTIFY_KINDS],
      architecture: 'Modules emit domain events. NotificationsService.dispatch is the only sender for push, SMS, email, and in-app.',
      push: this.fcm.configured() ? 'firebase' : 'in_app_only',
      firebaseProjectId: this.fcm.projectId() || 'karnacab-bf930',
      sms: 'log_adapter',
      email: this.email.configured() ? 'configured' : 'log',
      note: 'OTP uses SMS/email only so codes are not stored in the in-app inbox.',
    };
  }

  async list(actor: Actor) {
    const rows = await this.prisma.userNotification.findMany({
      where: { userId: actor.userId },
      orderBy: { createdAt: 'desc' },
      take: 80,
    });
    return {
      unread: rows.filter((row) => !row.readAt).length,
      push: this.fcm.configured() ? 'firebase' : 'in_app_only',
      firebaseProjectId: this.fcm.projectId() || 'karnacab-bf930',
      notifications: rows.map((row) => this.present(row)),
    };
  }

  async markRead(actor: Actor, id: bigint) {
    const row = await this.prisma.userNotification.findUnique({ where: { id } });
    if (!row || row.userId !== actor.userId) {
      throw new NotFoundException('Notification not found');
    }
    await this.prisma.userNotification.update({ where: { id }, data: { readAt: new Date() } });
    return this.list(actor);
  }

  async registerDevice(actor: Actor, token: string, platform = 'android') {
    const trimmed = token.trim();
    if (trimmed.length < 20) {
      return { ok: false };
    }
    await this.prisma.pushDevice.upsert({
      where: { token: trimmed },
      update: { userId: actor.userId, platform: platform.trim() || 'android' },
      create: { userId: actor.userId, token: trimmed, platform: platform.trim() || 'android' },
    });
    return { ok: true, push: this.fcm.configured() ? 'firebase' : 'in_app_only' };
  }

  async dispatch(input: DispatchInput) {
    const event = input.event.slice(0, 48);
    const template = templateFor(event);
    const vars = {
      ...(input.vars ?? {}),
      title: input.title ?? String(input.vars?.title ?? ''),
      body: input.body ?? String(input.vars?.body ?? ''),
    };
    const title = (input.title ?? fillTemplate(template.title, vars)).slice(0, 160) || event;
    const body = (input.body ?? fillTemplate(template.body, vars)).slice(0, 500) || title;
    const channels = (input.channels ?? template.channels).filter((item) =>
      (NOTIFY_CHANNELS as readonly string[]).includes(item),
    );
    let user =
      input.userId != null
        ? await this.prisma.user.findUnique({
            where: { id: input.userId },
            select: { id: true, phone: true, email: true },
          })
        : null;
    if (!user && input.phone) {
      user = await this.prisma.user.findFirst({
        where: { phone: input.phone },
        select: { id: true, phone: true, email: true },
      });
    }
    const userId = user?.id ?? input.userId ?? null;
    const phone = input.phone ?? user?.phone ?? null;
    const email = input.email ?? user?.email ?? null;
    const deliveries: Array<{ channel: string; status: string }> = [];
    for (const channel of channels) {
      const result = await this.sendChannel({
        channel,
        event,
        userId,
        phone,
        email,
        title,
        body,
        entity: input.entity,
      });
      deliveries.push(result);
    }
    return { event, userId: userId?.toString() ?? null, title, body, deliveries };
  }

  async notify(input: NotifyInput) {
    await this.dispatch({
      event: input.kind,
      userId: input.userId,
      title: input.title,
      body: input.body,
      entity: input.entity,
      channels: templateFor(input.kind).channels.includes('in_app')
        ? templateFor(input.kind).channels
        : ['in_app', 'push'],
    });
    return { ok: true, kind: input.kind };
  }

  async notifyUnlessRecent(input: NotifyInput, hours = 20) {
    const since = new Date(Date.now() - hours * 3600_000);
    const existing = await this.prisma.userNotification.findFirst({
      where: {
        userId: input.userId,
        kind: input.kind,
        entityId: input.entity?.id ?? null,
        createdAt: { gte: since },
      },
    });
    if (existing) {
      return this.present(existing);
    }
    await this.notify(input);
    return { ok: true, kind: input.kind };
  }

  async announce(actor: Actor, dto: { title: string; body: string; audience: string; kind?: string }) {
    if (actor.role !== UserRole.ADMIN && actor.role !== UserRole.SUPER_ADMIN) {
      throw new ForbiddenException('Admin only');
    }
    const roles =
      dto.audience === 'driver'
        ? [UserRole.DRIVER]
        : dto.audience === 'ops'
          ? [UserRole.ADMIN, UserRole.SUPER_ADMIN, UserRole.DISTRICT_HEAD, UserRole.STATE_HEAD, UserRole.FRANCHISE]
          : [UserRole.CUSTOMER, UserRole.CORPORATE];
    const users = await this.prisma.user.findMany({
      where: { role: { in: roles }, status: 'ACTIVE' },
      select: { id: true },
      take: 200,
    });
    for (const user of users) {
      await this.dispatch({
        event: 'announcement',
        userId: user.id,
        vars: { title: dto.title, body: dto.body },
        title: dto.title,
        body: dto.body,
      });
    }
    return { sent: users.length, kind: dto.kind || 'announcement' };
  }

  async notifyOps(input: { title: string; body: string; kind: string; districtId?: number | null; entity?: { type: string; id: string } }) {
    const where =
      input.districtId != null
        ? {
            status: 'ACTIVE' as const,
            OR: [
              { role: { in: [UserRole.ADMIN, UserRole.SUPER_ADMIN] } },
              { role: { in: [UserRole.DISTRICT_HEAD, UserRole.FRANCHISE] }, districtId: input.districtId },
              { role: UserRole.STATE_HEAD },
            ],
          }
        : { status: 'ACTIVE' as const, role: { in: [UserRole.ADMIN, UserRole.SUPER_ADMIN] } };
    const users = await this.prisma.user.findMany({ where, select: { id: true }, take: 40 });
    for (const user of users) {
      await this.dispatch({
        event: input.kind,
        userId: user.id,
        title: input.title,
        body: input.body,
        entity: input.entity,
        channels: ['in_app', 'push'],
      });
    }
  }

  private async sendChannel(input: {
    channel: string;
    event: string;
    userId: bigint | null;
    phone: string | null;
    email: string | null;
    title: string;
    body: string;
    entity?: { type: string; id: string };
  }) {
    let status = 'skipped';
    let note: string | null = null;
    const logBody = input.event === 'otp' ? 'OTP dispatched' : input.body;
    if (input.channel === 'in_app') {
      if (input.userId && input.event !== 'otp') {
        await this.prisma.userNotification.create({
          data: {
            userId: input.userId,
            title: input.title,
            body: input.body,
            kind: input.event.slice(0, 48),
            entityType: input.entity?.type,
            entityId: input.entity?.id,
          },
        });
        status = 'sent';
      } else {
        note = input.event === 'otp' ? 'otp_not_in_app' : 'no_user';
      }
    } else if (input.channel === 'push') {
      if (!input.userId) {
        note = 'no_user';
      } else {
        const devices = await this.prisma.pushDevice.findMany({
          where: { userId: input.userId },
          select: { token: true },
        });
        if (!devices.length) {
          note = 'no_device';
        } else {
          const result = await this.fcm.send(
            devices.map((item) => item.token),
            input.title,
            input.body,
            {
              event: input.event,
              title: input.title,
              body: input.body,
              ...(input.entity?.type ? { entityType: input.entity.type } : {}),
              ...(input.entity?.id ? { entityId: input.entity.id } : {}),
            },
          );
          status = result.sent > 0 ? 'sent' : 'skipped';
          note = result.skipped ? 'fcm_unconfigured' : result.sent === 0 ? 'fcm_failed' : null;
        }
      }
    } else if (input.channel === 'sms') {
      if (!input.phone) {
        note = 'no_phone';
      } else {
        await this.sms.send(input.phone, input.body);
        status = 'sent';
        note = 'log_adapter';
      }
    } else if (input.channel === 'email') {
      if (!input.email) {
        note = 'no_email';
      } else {
        const result = await this.email.send(input.email, input.title, input.body);
        status = result.sent > 0 ? 'sent' : 'skipped';
        note = result.skipped ? 'email_log' : null;
      }
    }
    await this.prisma.notificationDelivery.create({
      data: {
        userId: input.userId,
        event: input.event,
        channel: input.channel,
        title: input.title,
        body: logBody,
        status,
        providerNote: note,
        entityType: input.entity?.type,
        entityId: input.entity?.id,
      },
    });
    return { channel: input.channel, status };
  }

  private present(row: {
    id: bigint;
    title: string;
    body: string;
    kind: string;
    entityType: string | null;
    entityId: string | null;
    readAt: Date | null;
    createdAt: Date;
  }) {
    return {
      id: row.id.toString(),
      title: row.title,
      body: row.body,
      kind: row.kind,
      event: row.kind,
      entityType: row.entityType,
      entityId: row.entityId,
      read: Boolean(row.readAt),
      createdAt: row.createdAt.toISOString(),
    };
  }
}
