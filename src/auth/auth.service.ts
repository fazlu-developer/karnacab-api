import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  HttpException,
  HttpStatus,
  Injectable,
  Logger,
  UnauthorizedException,
} from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import { UserGender, UserRole, UserStatus } from '@prisma/client';
import { compare, hash } from 'bcrypt';
import { createHash, randomBytes, randomInt } from 'crypto';
import { PrismaService } from '../prisma/prisma.service';
import { RedisService } from '../redis/redis.service';
import { LoginDto, RegisterDto, DriverRegisterDto } from './dto/auth.dto';
import { nextOnboardingStep, isPlaceholderEmail } from './profile.util';
import { DomainEvents } from '../common/domain-events.service';
import { PlacesService } from '../places/places.service';
import { permissionsFor } from '../access/permissions';

const OTP_TTL_SECONDS = 300;
const OTP_COOLDOWN_SECONDS = 45;
const OTP_MAX_ATTEMPTS = 5;

/** Local dummy logins (never in production). Works even when Redis is down. */
const LOCAL_DEMO_OTP: Record<string, string> = {
  '9999999999': '123456',
  '9888888888': '123456',
};

function isLocalDemoOtp(phone: string, code?: string) {
  if (process.env.NODE_ENV === 'production') {
    return false;
  }
  const expected = LOCAL_DEMO_OTP[phone];
  if (!expected) {
    return false;
  }
  return code == null || code === expected;
}

@Injectable()
export class AuthService {
  private readonly logger = new Logger(AuthService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly jwt: JwtService,
    private readonly redis: RedisService,
    private readonly places: PlacesService,
    private readonly events: DomainEvents,
  ) {}

  async register(dto: RegisterDto) {
    const exists = await this.prisma.user.findUnique({
      where: { email: dto.email.toLowerCase() },
    });
    if (exists) {
      throw new ConflictException('Email already registered');
    }

    const user = await this.prisma.user.create({
      data: {
        role: UserRole.CUSTOMER,
        name: dto.name,
        email: dto.email.toLowerCase(),
        phone: dto.phone,
        passwordHash: await hash(dto.password, 10),
        wallets: {
          create: { ownerType: 'CUSTOMER', balancePaise: 0 },
        },
      },
    });

    return this.issue(user);
  }

  async registerAdvertiser(dto: RegisterDto) {
    const exists = await this.prisma.user.findUnique({
      where: { email: dto.email.toLowerCase() },
    });
    if (exists) {
      throw new ConflictException('Email already registered');
    }
    const user = await this.prisma.user.create({
      data: {
        role: UserRole.ADVERTISER,
        name: dto.name,
        email: dto.email.toLowerCase(),
        phone: dto.phone,
        passwordHash: await hash(dto.password, 10),
      },
    });
    return this.issue(user);
  }

  async registerDriver(dto: DriverRegisterDto) {
    const exists = await this.prisma.user.findUnique({
      where: { email: dto.email.toLowerCase() },
    });
    if (exists) {
      throw new ConflictException('Email already registered');
    }

    const user = await this.prisma.user.create({
      data: {
        status: UserStatus.PENDING,
        role: UserRole.DRIVER,
        name: dto.name,
        email: dto.email.toLowerCase(),
        phone: dto.phone,
        passwordHash: await hash(dto.password, 10),
        driver: {
          create: {
            licenseNo: dto.licenseNo.trim(),
            parcelEnabled: true,
            online: false,
            kycStatus: 'pending',
          },
        },
        wallets: {
          create: { ownerType: 'DRIVER', balancePaise: 0 },
        },
      },
    });

    return this.issue(user);
  }

  async login(dto: LoginDto, allowedRoles?: UserRole | UserRole[]) {
    const user = await this.prisma.user.findUnique({
      where: { email: dto.email.toLowerCase() },
    });
    if (!user || !(await compare(dto.password, user.passwordHash))) {
      throw new UnauthorizedException('Invalid credentials');
    }
    if (user.status === 'SUSPENDED') {
      throw new ForbiddenException('Account is suspended');
    }
    const roles = allowedRoles == null
      ? undefined
      : Array.isArray(allowedRoles)
        ? allowedRoles
        : [allowedRoles];
    if (roles && !roles.includes(user.role)) {
      throw new UnauthorizedException(
        'Use the correct KarnaCab app for this account',
      );
    }

    return this.issue(user);
  }

  async requestOtp(phone: string) {
    if (isLocalDemoOtp(phone)) {
      return {
        ok: true,
        expiresInSeconds: OTP_TTL_SECONDS,
        cooldownSeconds: 0,
        devCode: LOCAL_DEMO_OTP[phone],
      };
    }
    const cooldownKey = `otp:cd:${phone}`;
    const acquired = await this.redis.setNxEx(
      cooldownKey,
      OTP_COOLDOWN_SECONDS,
      '1',
    );
    if (!acquired) {
      throw new HttpException(
        'Please wait a few seconds before requesting another code',
        HttpStatus.TOO_MANY_REQUESTS,
      );
    }

    const code = String(randomInt(100000, 1000000));
    await this.redis.setex(
      `otp:login:${phone}`,
      OTP_TTL_SECONDS,
      this.hashOtp(phone, code),
    );
    await this.redis.del(`otp:attempts:${phone}`);

    this.logger.log({ msg: 'otp.requested', phone, purpose: 'LOGIN' });
    this.events.emit('auth.otp.requested', { phone, code });

    const payload: {
      ok: boolean;
      expiresInSeconds: number;
      cooldownSeconds: number;
      devCode?: string;
    } = {
      ok: true,
      expiresInSeconds: OTP_TTL_SECONDS,
      cooldownSeconds: OTP_COOLDOWN_SECONDS,
    };

    if (process.env.NODE_ENV !== 'production') {
      payload.devCode = code;
    }

    return payload;
  }

  async verifyOtp(phone: string, code: string) {
    if (isLocalDemoOtp(phone, code)) {
      const user = await this.findOrCreateCustomerByPhone(phone);
      return this.issue(user);
    }
    const stored = await this.redis.get(`otp:login:${phone}`);
    if (!stored) {
      throw new UnauthorizedException('Code expired. Request a new one.');
    }

    const attempts = await this.redis.incr(`otp:attempts:${phone}`);
    if (attempts === 1) {
      await this.redis.setex(`otp:attempts:${phone}`, OTP_TTL_SECONDS, '1');
    }
    if (attempts > OTP_MAX_ATTEMPTS) {
      await this.redis.del(`otp:login:${phone}`);
      throw new HttpException(
        'Too many incorrect attempts. Request a new code.',
        HttpStatus.TOO_MANY_REQUESTS,
      );
    }

    if (stored !== this.hashOtp(phone, code)) {
      throw new UnauthorizedException('Incorrect verification code');
    }

    await this.redis.del(`otp:login:${phone}`);
    await this.redis.del(`otp:attempts:${phone}`);

    const user = await this.findOrCreateCustomerByPhone(phone);
    return this.issue(user);
  }

  async verifyDriverOtp(phone: string, code: string) {
    if (isLocalDemoOtp(phone, code)) {
      const user = await this.findOrCreateDriverByPhone(phone);
      return this.issue(user);
    }
    const stored = await this.redis.get(`otp:login:${phone}`);
    if (!stored) {
      throw new UnauthorizedException('Code expired. Request a new one.');
    }

    const attempts = await this.redis.incr(`otp:attempts:${phone}`);
    if (attempts === 1) {
      await this.redis.setex(`otp:attempts:${phone}`, OTP_TTL_SECONDS, '1');
    }
    if (attempts > OTP_MAX_ATTEMPTS) {
      await this.redis.del(`otp:login:${phone}`);
      throw new HttpException(
        'Too many incorrect attempts. Request a new code.',
        HttpStatus.TOO_MANY_REQUESTS,
      );
    }

    if (stored !== this.hashOtp(phone, code)) {
      throw new UnauthorizedException('Incorrect verification code');
    }

    await this.redis.del(`otp:login:${phone}`);
    await this.redis.del(`otp:attempts:${phone}`);

    const user = await this.findOrCreateDriverByPhone(phone);
    return this.issue(user);
  }

  async me(userId: bigint) {
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
      select: {
        id: true,
        role: true,
        name: true,
        email: true,
        phone: true,
        status: true,
        dateOfBirth: true,
        gender: true,
        lastLat: true,
        lastLng: true,
        lastAddress: true,
        locationUpdatedAt: true,
        profileCompletedAt: true,
        districtId: true,
        stateId: true,
      },
    });
    if (!user) {
      throw new UnauthorizedException();
    }
    return this.serializeUser(user);
  }

  async completeProfile(
    userId: bigint,
    dto: {
      name: string;
      email: string;
      dateOfBirth: string;
      gender: UserGender;
    },
  ) {
    const birth = new Date(dto.dateOfBirth);
    if (Number.isNaN(birth.getTime())) {
      throw new BadRequestException('Enter a valid date of birth');
    }
    const ageMs = Date.now() - birth.getTime();
    const ageYears = ageMs / (365.25 * 24 * 60 * 60 * 1000);
    if (ageYears < 18) {
      throw new BadRequestException('You must be at least 18 years old');
    }

    const email = dto.email.toLowerCase().trim();
    const taken = await this.prisma.user.findFirst({
      where: { email, NOT: { id: userId } },
    });
    if (taken) {
      throw new ConflictException('Email already registered');
    }

    const user = await this.prisma.user.update({
      where: { id: userId },
      data: {
        name: dto.name.trim(),
        email,
        dateOfBirth: birth,
        gender: dto.gender,
        profileCompletedAt: new Date(),
      },
    });
    return this.serializeUser(user);
  }

  async updateLocation(
    userId: bigint,
    dto: { lat: number; lng: number; address: string },
  ) {
    const user = await this.prisma.user.update({
      where: { id: userId },
      data: {
        lastLat: dto.lat,
        lastLng: dto.lng,
        lastAddress: dto.address.trim(),
        locationUpdatedAt: new Date(),
      },
    });
    await this.places.saveCurrentLocation(
      userId,
      dto.address.trim(),
      dto.lat,
      dto.lng,
    );
    return this.serializeUser(user);
  }

  private serializeUser(user: {
    id: bigint;
    role: UserRole;
    name: string;
    email: string;
    phone?: string | null;
    status?: string;
    dateOfBirth?: Date | null;
    gender?: UserGender | null;
    lastLat?: unknown;
    lastLng?: unknown;
    lastAddress?: string | null;
    locationUpdatedAt?: Date | null;
    profileCompletedAt?: Date | null;
    districtId?: number | null;
    stateId?: number | null;
  }) {
    const nextStep = nextOnboardingStep({
      name: user.name,
      email: user.email,
      dateOfBirth: user.dateOfBirth ?? null,
      gender: user.gender ?? null,
      lastLat: user.lastLat,
      lastLng: user.lastLng,
    });
    return {
      id: user.id.toString(),
      role: user.role,
      name: user.name,
      email: isPlaceholderEmail(user.email) ? '' : user.email,
      phone: user.phone ?? null,
      status: user.status,
      dateOfBirth: user.dateOfBirth
        ? user.dateOfBirth.toISOString().slice(0, 10)
        : null,
      gender: user.gender ?? null,
      lastLat: user.lastLat == null ? null : Number(user.lastLat),
      lastLng: user.lastLng == null ? null : Number(user.lastLng),
      lastAddress: user.lastAddress ?? null,
      locationUpdatedAt: user.locationUpdatedAt ?? null,
      profileCompletedAt: user.profileCompletedAt ?? null,
      profileComplete: nextStep !== 'PROFILE',
      locationSet: nextStep === 'HOME',
      nextStep,
      permissions: permissionsFor(user.role),
      districtId: user.districtId ?? null,
      stateId: user.stateId ?? null,
    };
  }

  private issue(user: {
    id: bigint;
    role: UserRole;
    name: string;
    email: string;
    districtId?: number | null;
    stateId?: number | null;
  }) {
    const payload = { sub: user.id.toString(), role: user.role };
    this.events.emit('auth.login', { userId: user.id.toString(), role: user.role });
    return {
      accessToken: this.jwt.sign(payload),
      tokenType: 'Bearer',
      user: {
        id: user.id.toString(),
        role: user.role,
        name: user.name,
        email: user.email,
        permissions: permissionsFor(user.role),
        districtId: user.districtId ?? null,
        stateId: user.stateId ?? null,
      },
    };
  }

  private hashOtp(phone: string, code: string): string {
    return createHash('sha256').update(`${phone}:${code}`).digest('hex');
  }

  private async findOrCreateDriverByPhone(phone: string) {
    const existing = await this.prisma.user.findUnique({
      where: { phone },
      include: { driver: true },
    });
    if (existing) {
      if (existing.role !== UserRole.DRIVER) {
        throw new ForbiddenException(
          'Use the KarnaCab customer app for this number',
        );
      }
      if (existing.status === 'SUSPENDED') {
        throw new ForbiddenException('Account is suspended');
      }
      return existing;
    }

    return this.prisma.user.create({
      data: {
        role: UserRole.DRIVER,
        status: UserStatus.PENDING,
        name: 'KarnaCab driver',
        email: `91${phone}@driver.karnacab.local`,
        phone,
        passwordHash: await hash(randomBytes(24).toString('hex'), 10),
        driver: {
          create: {
            licenseNo: 'PENDING',
            parcelEnabled: false,
            online: false,
            kycStatus: 'pending',
          },
        },
        wallets: {
          create: { ownerType: 'DRIVER', balancePaise: 0 },
        },
      },
    });
  }

  private async findOrCreateCustomerByPhone(phone: string) {
    const existing = await this.prisma.user.findUnique({ where: { phone } });
    if (existing) {
      if (existing.role !== UserRole.CUSTOMER) {
        throw new ForbiddenException(
          'Use the KarnaCab driver app for this number',
        );
      }
      return existing;
    }

    return this.prisma.user.create({
      data: {
        role: UserRole.CUSTOMER,
        name: 'KarnaCab rider',
        email: `91${phone}@phone.karnacab.local`,
        phone,
        passwordHash: await hash(randomBytes(24).toString('hex'), 10),
        wallets: {
          create: { ownerType: 'CUSTOMER', balancePaise: 0 },
        },
      },
    });
  }
}
