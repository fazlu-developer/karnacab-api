import { ApiProperty } from '@nestjs/swagger';
import { Type } from 'class-transformer';
import {
  IsBoolean,
  IsEmail,
  IsEnum,
  IsIn,
  IsInt,
  IsOptional,
  IsString,
  MaxLength,
  MinLength,
} from 'class-validator';
import { UserRole, UserStatus } from '@prisma/client';

export class OpsBookingQueryDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  q?: string;

  @ApiProperty({ required: false, description: 'Public booking ref e.g. KCDEMO0001' })
  @IsOptional()
  @IsString()
  publicRef?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  customer?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  driver?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  vehicle?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  product?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  status?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  paymentStatus?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  stateId?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  districtId?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  fleetId?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  franchiseId?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  from?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  to?: string;
}

export class OpsUserQueryDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  q?: string;

  @ApiProperty({ required: false, enum: UserRole })
  @IsOptional()
  @IsEnum(UserRole)
  role?: UserRole;

  @ApiProperty({ required: false, enum: UserStatus })
  @IsOptional()
  @IsEnum(UserStatus)
  status?: UserStatus;
}

export class CreateOpsUserDto {
  @IsString()
  @MinLength(2)
  @MaxLength(120)
  name!: string;

  @IsEmail()
  email!: string;

  @IsOptional()
  @IsString()
  @MaxLength(20)
  phone?: string;

  @IsString()
  @MinLength(8)
  @MaxLength(72)
  password!: string;

  @IsOptional()
  @IsEnum(UserRole)
  role?: UserRole;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  stateId?: number;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  districtId?: number;
}

export class PatchOpsUserDto {
  @IsOptional()
  @IsString()
  @MinLength(2)
  @MaxLength(120)
  name?: string;

  @IsOptional()
  @IsString()
  @MaxLength(20)
  phone?: string;

  @IsOptional()
  @IsEnum(UserRole)
  role?: UserRole;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  stateId?: number;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  districtId?: number;

  @IsOptional()
  @IsString()
  @MaxLength(120)
  emergencyName?: string;

  @IsOptional()
  @IsString()
  @MaxLength(20)
  emergencyPhone?: string;
}

export class PatchOpsUserStatusDto {
  @IsEnum(UserStatus)
  status!: UserStatus;
}

export class PatchOpsDriverDto {
  @IsOptional()
  @IsString()
  action?: 'approve' | 'reject' | 'suspend' | 'activate';

  @IsOptional()
  @IsString()
  @MaxLength(500)
  reason?: string;

  @IsOptional()
  @IsString()
  fleetOwnerId?: string;

  @IsOptional()
  @IsString()
  vehicleId?: string;
}

export class UpsertCouponDto {
  @IsString()
  @MaxLength(32)
  code!: string;

  @IsString()
  @MaxLength(160)
  title!: string;

  @IsOptional()
  @IsString()
  @MaxLength(255)
  subtitle?: string;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  percent?: number;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  amountPaise?: number;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  minFarePaise?: number;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  maxDiscountPaise?: number;

  @IsOptional()
  @IsIn(['percent', 'fixed'])
  kind?: 'percent' | 'fixed';

  @IsOptional()
  @IsString()
  product?: string;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  stateId?: number;

  @IsOptional()
  @IsIn(['all', 'new_user', 'existing_user', 'first_ride', 'corporate'])
  audience?: string;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  usageLimit?: number;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  userLimit?: number;

  @IsString()
  startsOn!: string;

  @IsString()
  endsOn!: string;

  @IsOptional()
  @IsBoolean()
  active?: boolean;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  districtId?: number;
}

export class PatchSettingDto {
  @IsString()
  @MaxLength(100)
  key!: string;

  @IsString()
  value!: string;
}
