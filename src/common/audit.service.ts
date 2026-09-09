import { Injectable, Logger } from '@nestjs/common';
import { Prisma } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';

@Injectable()
export class AuditService {
  private readonly logger = new Logger(AuditService.name);

  constructor(private readonly prisma: PrismaService) {}

  async record(input: {
    actorUserId?: bigint | null;
    domain: string;
    action: string;
    entityType?: string | null;
    entityId?: string | null;
    payload?: Prisma.InputJsonValue;
  }) {
    try {
      await this.prisma.platformAuditEvent.create({
        data: {
          actorUserId: input.actorUserId ?? null,
          domain: input.domain,
          action: input.action,
          entityType: input.entityType ?? null,
          entityId: input.entityId ?? null,
          payload: input.payload ?? undefined,
        },
      });
    } catch (error) {
      this.logger.warn({ err: error }, 'Could not persist platform audit event');
    }
  }
}
