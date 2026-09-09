import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { UserGender, VehicleCategory } from '@prisma/client';
import { createHash } from 'crypto';
import { PrismaService } from '../prisma/prisma.service';
import { DomainEvents } from '../common/domain-events.service';
import { KycStorage } from './kyc.storage';
import {
  ALLOWED_MIME,
  DEFAULT_OPTIONAL_DOCS,
  DEFAULT_REQUIRED_DOCS,
  DOC_LABELS,
  DOC_TYPES,
  DocStatus,
  DocType,
} from './kyc.types';
import { alertsForDocument } from '../drivers/driver-document-alerts';
import {
  PatchDriverBankDto,
  PatchDriverProfileDto,
  PatchDriverVehicleDto,
  ReviewDocumentDto,
  ReviewKycDto,
  UploadDriverDocumentDto,
} from './dto/kyc.dto';

const PLACEHOLDER_LICENSE = 'PENDING';

@Injectable()
export class KycService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly storage: KycStorage,
    private readonly config: ConfigService,
    private readonly events: DomainEvents,
  ) {}

  async catalog() {
    const [districts, requiredRow, optionalRow, bankRow] = await Promise.all([
      this.prisma.district.findMany({
        select: { id: true, name: true, code: true, state: { select: { name: true } } },
        orderBy: { name: 'asc' },
      }),
      this.prisma.systemSetting.findUnique({ where: { key: 'driver_kyc_required_docs' } }),
      this.prisma.systemSetting.findUnique({ where: { key: 'driver_kyc_optional_docs' } }),
      this.prisma.systemSetting.findUnique({ where: { key: 'driver_kyc_bank_required' } }),
    ]);
    const required = this.parseDocs(requiredRow?.value, DEFAULT_REQUIRED_DOCS);
    const optional = this.parseDocs(optionalRow?.value, DEFAULT_OPTIONAL_DOCS);
    return {
      cities: districts.map((row) => ({
        id: row.id,
        name: row.name,
        area: row.state.name,
        code: row.code,
      })),
      requiredDocs: required.map((type) => ({ type, label: DOC_LABELS[type] })),
      optionalDocs: optional.map((type) => ({ type, label: DOC_LABELS[type] })),
      bankRequired: (bankRow?.value ?? 'true').toLowerCase() !== 'false',
      documentStatuses: ['pending', 'under_review', 'verified', 'rejected', 'expired'],
    };
  }

  async snapshot(userId: bigint) {
    const driver = await this.requireDriver(userId);
    await this.expireOverdue(driver.id);
    const fresh = await this.requireDriver(userId);
    const required = await this.requiredTypes();
    const optional = await this.optionalTypes();
    const docs = fresh.documents.map((row) => this.serializeDoc(row));
    const byType = Object.fromEntries(docs.map((row) => [row.type, row]));
    const canGoOnline = this.computeCanGoOnline(fresh.kycStatus, docs, required);
    return {
      id: fresh.id.toString(),
      userId: fresh.userId.toString(),
      kycStatus: fresh.kycStatus,
      canGoOnline,
      online: fresh.online,
      city: fresh.city,
      licenseNo: fresh.licenseNo === PLACEHOLDER_LICENSE ? '' : fresh.licenseNo,
      termsAccepted: Boolean(fresh.termsAcceptedAt),
      submittedAt: fresh.applicationSubmittedAt?.toISOString() ?? null,
      rejectedReason: fresh.kycRejectedReason,
      name: fresh.user.name,
      email: fresh.user.email,
      phone: fresh.user.phone,
      dateOfBirth: fresh.user.dateOfBirth?.toISOString().slice(0, 10) ?? null,
      gender: fresh.user.gender,
      districtId: fresh.user.districtId,
      identity: {
        type: fresh.idType,
        last4: fresh.idLast4,
      },
      emergency: {
        name: fresh.emergencyName,
        phone: fresh.emergencyPhone,
      },
      bank: {
        accountHolder: fresh.bankAccountHolder,
        ifsc: fresh.bankIfsc,
        accountLast4: fresh.bankAccountLast4,
        upiId: fresh.upiId,
        required: (await this.catalog()).bankRequired,
      },
      vehicle: fresh.vehicles[0]
        ? {
            category: fresh.vehicles[0].category,
            registrationNo: fresh.vehicles[0].registrationNo.startsWith('KYC')
              ? ''
              : fresh.vehicles[0].registrationNo,
            brand: fresh.vehicles[0].brand,
            model: fresh.vehicles[0].model,
            year: fresh.vehicles[0].year,
            color: fresh.vehicles[0].color,
            fuel: fresh.vehicles[0].fuel,
          }
        : null,
      documents: docs,
      documentAlerts: docs.flatMap((doc) => alertsForDocument(doc)),
      documentsByType: byType,
      requiredDocs: required,
      optionalDocs: optional,
      missingRequired: required.filter((type) => {
        const doc = byType[type];
        return !doc || ['rejected', 'expired'].includes(doc.status);
      }),
      nextStep: this.nextStep(fresh, byType, required),
    };
  }

  computeCanGoOnline(
    kycStatus: string,
    docs: { type: string; status: string }[],
    required: DocType[],
  ) {
    if (kycStatus !== 'verified') {
      return false;
    }
    const byType = Object.fromEntries(docs.map((row) => [row.type, row]));
    return required.every((type) => byType[type]?.status === 'verified');
  }

  async assertCanGoOnline(userId: bigint) {
    const snap = await this.snapshot(userId);
    if (!snap.canGoOnline) {
      throw new ForbiddenException(
        'Complete driver verification before going online',
      );
    }
  }

  async patchProfile(userId: bigint, dto: PatchDriverProfileDto) {
    const driver = await this.requireDriver(userId);
    const name = [dto.firstName, dto.lastName].filter(Boolean).join(' ').trim();
    let idLast4: string | undefined;
    let idHash: string | undefined;
    if (dto.idNumber) {
      const cleaned = dto.idNumber.replace(/\s/g, '');
      idLast4 = cleaned.slice(-4);
      idHash = createHash('sha256').update(cleaned).digest('hex');
    }
    let districtId = dto.districtId;
    if (!districtId && dto.city) {
      const match = await this.prisma.district.findFirst({
        where: { name: dto.city },
      });
      districtId = match?.id;
    }
    await this.prisma.$transaction([
      this.prisma.user.update({
        where: { id: userId },
        data: {
          ...(name ? { name } : {}),
          ...(dto.email ? { email: dto.email.toLowerCase() } : {}),
          ...(dto.dateOfBirth ? { dateOfBirth: new Date(dto.dateOfBirth) } : {}),
          ...(dto.gender ? { gender: dto.gender as UserGender } : {}),
          ...(dto.address ? { lastAddress: `${dto.address}, ${dto.city ?? ''} ${dto.pinCode ?? ''}`.trim() } : {}),
          ...(districtId ? { districtId } : {}),
        },
      }),
      this.prisma.driver.update({
        where: { id: driver.id },
        data: {
          ...(dto.city ? { city: dto.city } : {}),
          ...(dto.licenseNo ? { licenseNo: dto.licenseNo.trim() } : {}),
          ...(dto.idType ? { idType: dto.idType } : {}),
          ...(idLast4 ? { idLast4, idHash } : {}),
          ...(dto.termsAccepted
            ? { termsAcceptedAt: driver.termsAcceptedAt ?? new Date() }
            : {}),
          ...(dto.emergencyName !== undefined ? { emergencyName: dto.emergencyName.trim() || null } : {}),
          ...(dto.emergencyPhone !== undefined ? { emergencyPhone: dto.emergencyPhone.replace(/\D/g, '') || null } : {}),
        },
      }),
    ]);
    return this.snapshot(userId);
  }

  async patchVehicle(userId: bigint, dto: PatchDriverVehicleDto) {
    const driver = await this.requireDriver(userId);
    const districtId =
      driver.user.districtId ??
      (await this.prisma.district.findFirst({ orderBy: { id: 'asc' } }))?.id;
    if (!districtId) {
      throw new BadRequestException('Select a city before saving the vehicle');
    }
    const existing = driver.vehicles[0];
    const category = (dto.category as VehicleCategory | undefined) ?? existing?.category ?? VehicleCategory.SEDAN;
    const registrationNo = dto.registrationNo?.replace(/\s/g, '').toUpperCase() ?? existing?.registrationNo ?? `KYC${driver.id.toString()}`;
    if (existing) {
      await this.prisma.vehicle.update({
        where: { id: existing.id },
        data: {
          category,
          registrationNo,
          districtId,
          brand: dto.brand ?? existing.brand,
          model: dto.model ?? existing.model,
          year: dto.year ?? existing.year,
          color: dto.color ?? existing.color,
          fuel: dto.fuel ?? existing.fuel,
        },
      });
    } else {
      await this.prisma.vehicle.create({
        data: {
          driverId: driver.id,
          districtId,
          category,
          registrationNo,
          status: 'offline',
          brand: dto.brand,
          model: dto.model,
          year: dto.year,
          color: dto.color,
          fuel: dto.fuel,
        },
      });
    }
    return this.snapshot(userId);
  }

  async patchBank(userId: bigint, dto: PatchDriverBankDto) {
    const driver = await this.requireDriver(userId);
    const account = dto.accountNumber?.replace(/\s/g, '');
    await this.prisma.driver.update({
      where: { id: driver.id },
      data: {
        bankAccountHolder: dto.accountHolder ?? driver.bankAccountHolder,
        bankIfsc: dto.ifsc?.toUpperCase() ?? driver.bankIfsc,
        upiId: dto.upiId ?? driver.upiId,
        ...(account
          ? {
              bankAccountLast4: account.slice(-4),
              bankAccountHash: createHash('sha256').update(account).digest('hex'),
            }
          : {}),
      },
    });
    return this.snapshot(userId);
  }

  async upload(userId: bigint, dto: UploadDriverDocumentDto) {
    if (!DOC_TYPES.includes(dto.type as DocType)) {
      throw new BadRequestException('Unknown document type');
    }
    const mime = dto.mime.toLowerCase();
    if (!ALLOWED_MIME.has(mime)) {
      throw new BadRequestException('Upload a JPEG, PNG, WebP, or PDF');
    }
    const buffer = this.decodeFile(dto.fileBase64);
    const max = this.config.get<number>('kyc.maxBytes') ?? 4 * 1024 * 1024;
    if (buffer.length > max) {
      throw new BadRequestException('Document is too large');
    }
    const driver = await this.requireDriver(userId);
    const existing = driver.documents.find((row) => row.type === dto.type);
    if (existing) {
      await this.storage.remove(existing.storageKey);
    }
    const ext = mime === 'application/pdf' ? 'pdf' : mime.split('/')[1] ?? 'bin';
    const storageKey = await this.storage.write(
      driver.id.toString(),
      dto.type,
      buffer,
      ext,
      mime,
    );
    const checksumSha256 = createHash('sha256').update(buffer).digest('hex');
    const data = {
      type: dto.type,
      status: 'pending' as const,
      storageKey,
      originalName: dto.originalName ?? `${dto.type.toLowerCase()}.${ext}`,
      mime,
      sizeBytes: buffer.length,
      checksumSha256,
      expiresAt: dto.expiresAt ? new Date(dto.expiresAt) : null,
      rejectionReason: null,
      reviewedAt: null,
      reviewedByUserId: null,
    };
    if (existing) {
      await this.prisma.driverDocument.update({
        where: { id: existing.id },
        data,
      });
    } else {
      await this.prisma.driverDocument.create({
        data: { driverId: driver.id, ...data },
      });
    }
    if (driver.kycStatus === 'verified') {
      await this.prisma.driver.update({
        where: { id: driver.id },
        data: { kycStatus: 'under_review', online: false },
      });
    }
    return this.snapshot(userId);
  }

  async fileForDriver(userId: bigint, documentId: bigint) {
    const driver = await this.requireDriver(userId);
    const doc = driver.documents.find((row) => row.id === documentId);
    if (!doc) {
      throw new NotFoundException('Document not found');
    }
    const buffer = await this.storage.read(doc.storageKey);
    return { buffer, mime: doc.mime, name: doc.originalName ?? `${doc.type}.bin` };
  }

  async fileForOps(documentId: bigint) {
    const doc = await this.prisma.driverDocument.findUnique({ where: { id: documentId } });
    if (!doc) {
      throw new NotFoundException('Document not found');
    }
    const buffer = await this.storage.read(doc.storageKey);
    return { buffer, mime: doc.mime, name: doc.originalName ?? `${doc.type}.bin` };
  }

  async submit(userId: bigint, termsAccepted: boolean) {
    if (!termsAccepted) {
      throw new BadRequestException('Accept the driver terms to submit');
    }
    const snap = await this.snapshot(userId);
    if (snap.missingRequired.length) {
      throw new BadRequestException(
        `Upload required documents: ${snap.missingRequired.join(', ')}`,
      );
    }
    const catalog = await this.catalog();
    if (catalog.bankRequired && !snap.bank.accountLast4 && !snap.bank.upiId) {
      throw new BadRequestException('Add bank or UPI details before submitting');
    }
    const driver = await this.requireDriver(userId);
    await this.prisma.driver.update({
      where: { id: driver.id },
      data: {
        termsAcceptedAt: driver.termsAcceptedAt ?? new Date(),
        applicationSubmittedAt: new Date(),
        kycStatus: 'under_review',
        kycRejectedReason: null,
        online: false,
      },
    });
    await this.prisma.driverDocument.updateMany({
      where: {
        driverId: driver.id,
        status: { in: ['pending'] },
      },
      data: { status: 'under_review' },
    });
    this.events.emit('kyc.submitted', { driverUserId: userId.toString() });
    return this.snapshot(userId);
  }

  async opsDriverUserId(driverId: bigint) {
    const driver = await this.prisma.driver.findUnique({
      where: { id: driverId },
      select: { userId: true },
    });
    if (!driver) {
      throw new NotFoundException('Driver not found');
    }
    return driver.userId;
  }

  async listQueue() {
    const rows = await this.prisma.driver.findMany({
      where: { kycStatus: { in: ['pending', 'under_review', 'rejected'] } },
      include: {
        user: { select: { name: true, phone: true, email: true } },
        documents: true,
      },
      orderBy: { updatedAt: 'desc' },
      take: 100,
    });
    return {
      applications: rows.map((row) => ({
        id: row.id.toString(),
        userId: row.userId.toString(),
        kycStatus: row.kycStatus,
        name: row.user.name,
        phone: row.user.phone,
        city: row.city,
        submittedAt: row.applicationSubmittedAt?.toISOString() ?? null,
        documents: row.documents.map((doc) => this.serializeDoc(doc)),
      })),
    };
  }

  async reviewDocument(driverId: bigint, documentId: bigint, actorUserId: bigint, dto: ReviewDocumentDto) {
    if (dto.status === 'rejected' && !dto.rejectionReason?.trim()) {
      throw new BadRequestException('Rejection reason is required');
    }
    const doc = await this.prisma.driverDocument.findFirst({
      where: { id: documentId, driverId },
    });
    if (!doc) {
      throw new NotFoundException('Document not found');
    }
    await this.prisma.driverDocument.update({
      where: { id: doc.id },
      data: {
        status: dto.status,
        rejectionReason: dto.status === 'rejected' ? dto.rejectionReason!.trim() : null,
        reviewedAt: new Date(),
        reviewedByUserId: actorUserId,
      },
    });
    await this.syncApplication(driverId);
    const driver = await this.prisma.driver.findUniqueOrThrow({
      where: { id: driverId },
      select: { userId: true },
    });
    return this.snapshot(driver.userId);
  }

  async reviewApplication(driverId: bigint, dto: ReviewKycDto) {
    const driver = await this.prisma.driver.findUnique({
      where: { id: driverId },
      include: { documents: true, user: true },
    });
    if (!driver) {
      throw new NotFoundException('Driver not found');
    }
    if (dto.status === 'verified') {
      const required = await this.requiredTypes();
      await this.expireOverdue(driver.id);
      const docs = await this.prisma.driverDocument.findMany({ where: { driverId } });
      const ok = required.every((type) => docs.find((row) => row.type === type)?.status === 'verified');
      if (!ok) {
        throw new BadRequestException('Verify every required document first');
      }
      await this.prisma.driver.update({
        where: { id: driverId },
        data: { kycStatus: 'verified', kycRejectedReason: null },
      });
      await this.prisma.user.update({
        where: { id: driver.userId },
        data: { status: 'ACTIVE' },
      });
    } else if (dto.status === 'rejected') {
      await this.prisma.driver.update({
        where: { id: driverId },
        data: {
          kycStatus: 'rejected',
          kycRejectedReason: dto.reason ?? 'Application rejected',
          online: false,
        },
      });
    } else {
      await this.prisma.driver.update({
        where: { id: driverId },
        data: { kycStatus: 'under_review' },
      });
    }
    if (dto.status === 'verified' || dto.status === 'rejected') {
      this.events.emit('kyc.reviewed', {
        driverUserId: driver.userId.toString(),
        status: dto.status,
        reason: dto.reason,
      });
    }
    return this.snapshot(driver.userId);
  }

  private async syncApplication(driverId: bigint) {
    const required = await this.requiredTypes();
    await this.expireOverdue(driverId);
    const driver = await this.prisma.driver.findUniqueOrThrow({
      where: { id: driverId },
      include: { documents: true },
    });
    const byType = Object.fromEntries(driver.documents.map((row) => [row.type, row]));
    const allVerified = required.every((type) => byType[type]?.status === 'verified');
    const anyRejected = required.some((type) => byType[type]?.status === 'rejected');
    const anyExpired = required.some((type) => byType[type]?.status === 'expired');
    if (allVerified) {
      await this.prisma.driver.update({
        where: { id: driverId },
        data: { kycStatus: 'verified', kycRejectedReason: null },
      });
      await this.prisma.user.update({
        where: { id: driver.userId },
        data: { status: 'ACTIVE' },
      });
      this.events.emit('kyc.reviewed', {
        driverUserId: driver.userId.toString(),
        status: 'verified',
      });
      return;
    }
    if (anyExpired || anyRejected) {
      await this.prisma.driver.update({
        where: { id: driverId },
        data: {
          kycStatus: driver.applicationSubmittedAt ? 'under_review' : 'pending',
          online: false,
        },
      });
    }
  }

  private async expireOverdue(driverId: bigint) {
    await this.prisma.driverDocument.updateMany({
      where: {
        driverId,
        expiresAt: { lt: new Date() },
        status: { not: 'expired' },
      },
      data: { status: 'expired' },
    });
  }

  private nextStep(
    driver: { kycStatus: string; city: string | null; termsAcceptedAt: Date | null; applicationSubmittedAt: Date | null; licenseNo: string; idLast4: string | null; bankAccountLast4: string | null; upiId: string | null; vehicles: { brand: string | null }[] },
    byType: Record<string, { status: string }>,
    required: DocType[],
  ) {
    if (driver.kycStatus === 'verified') return 'home';
    if (driver.kycStatus === 'under_review' && driver.applicationSubmittedAt) return 'review';
    if (!driver.city) return 'city';
    if (!driver.termsAcceptedAt) return 'terms';
    if (!driver.idLast4) return 'identity';
    for (const type of required) {
      if (!byType[type] || byType[type].status === 'rejected' || byType[type].status === 'expired') {
        return type.toLowerCase();
      }
    }
    if (!driver.vehicles[0]?.brand) return 'vehicle';
    if (!driver.bankAccountLast4 && !driver.upiId) return 'bank';
    return 'submit';
  }

  private serializeDoc(row: {
    id: bigint;
    type: string;
    status: string;
    storageKey: string;
    expiresAt: Date | null;
    rejectionReason: string | null;
    originalName: string | null;
    mime: string;
    reviewedAt: Date | null;
    createdAt: Date;
    updatedAt: Date;
  }) {
    let status: DocStatus = row.status as DocStatus;
    if (row.expiresAt && row.expiresAt < new Date() && status !== 'expired') {
      status = 'expired';
    }
    return {
      id: row.id.toString(),
      type: row.type,
      label: DOC_LABELS[row.type as DocType] ?? row.type,
      status,
      expiresAt: row.expiresAt?.toISOString().slice(0, 10) ?? null,
      rejectionReason: row.rejectionReason,
      originalName: row.originalName,
      mime: row.mime,
      fileUrl: this.storage.publicUrl(row.storageKey),
      previewPath: `/drivers/me/documents/${row.id.toString()}/file`,
      opsPreviewPath: `/ops/kyc/documents/${row.id.toString()}/file`,
      uploadedAt: row.createdAt.toISOString(),
      reviewedAt: row.reviewedAt?.toISOString() ?? null,
    };
  }

  private decodeFile(raw: string) {
    const cleaned = raw.includes(',') ? raw.slice(raw.indexOf(',') + 1) : raw;
    const buffer = Buffer.from(cleaned, 'base64');
    if (!buffer.length) {
      throw new BadRequestException('Document file is empty');
    }
    return buffer;
  }

  private parseDocs(value: string | undefined, fallback: DocType[]): DocType[] {
    if (!value) return fallback;
    try {
      const parsed = JSON.parse(value) as string[];
      return parsed.filter((item): item is DocType => DOC_TYPES.includes(item as DocType));
    } catch {
      return fallback;
    }
  }

  private async requiredTypes() {
    const row = await this.prisma.systemSetting.findUnique({ where: { key: 'driver_kyc_required_docs' } });
    return this.parseDocs(row?.value, DEFAULT_REQUIRED_DOCS);
  }

  private async optionalTypes() {
    const row = await this.prisma.systemSetting.findUnique({ where: { key: 'driver_kyc_optional_docs' } });
    return this.parseDocs(row?.value, DEFAULT_OPTIONAL_DOCS);
  }

  private async requireDriver(userId: bigint) {
    const driver = await this.prisma.driver.findUnique({
      where: { userId },
      include: {
        user: true,
        documents: true,
        vehicles: true,
      },
    });
    if (!driver) {
      throw new ForbiddenException('Driver profile required');
    }
    return driver;
  }
}
