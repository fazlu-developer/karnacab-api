import { Type } from 'class-transformer';
import {
  IsBoolean,
  IsDateString,
  IsEmail,
  IsIn,
  IsInt,
  IsOptional,
  IsString,
  MaxLength,
  MinLength,
} from 'class-validator';
import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { VehicleCategory } from '@prisma/client';
import { FLEET_LOCKED_STATUSES, VEHICLE_DOC_TYPES } from '../fleet-vehicle-status';

const CATEGORIES = ['BIKE', 'AUTO', 'E_RICKSHAW', 'MINI', 'SEDAN', 'SUV', 'TRAVELLER'] as const;
const PATCH_STATUSES = ['available', 'offline', 'maintenance', 'suspended'] as const;

export class CreateFleetVehicleDto {
  @ApiProperty()
  @IsString()
  @MinLength(4)
  @MaxLength(20)
  registrationNo!: string;

  @ApiProperty({ enum: CATEGORIES })
  @IsIn([...CATEGORIES])
  category!: VehicleCategory;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  brand?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  model?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  year?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  color?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  fuel?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  districtId?: number;
}

export class PatchFleetVehicleDto {
  @ApiPropertyOptional({ enum: PATCH_STATUSES })
  @IsOptional()
  @IsIn([...PATCH_STATUSES])
  status?: (typeof PATCH_STATUSES)[number];

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  brand?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  model?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  year?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  color?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsBoolean()
  parcelEnabled?: boolean;
}

export class CreateFleetDriverDto {
  @ApiProperty()
  @IsString()
  @MinLength(2)
  name!: string;

  @ApiProperty()
  @IsEmail()
  email!: string;

  @ApiProperty()
  @IsString()
  @MinLength(10)
  @MaxLength(15)
  phone!: string;

  @ApiProperty()
  @IsString()
  @MinLength(8)
  password!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  licenseNo?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  city?: string;
}

export class AssignFleetDriverDto {
  @ApiProperty()
  @IsString()
  vehicleId!: string;
}

export class UnassignFleetDriverDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  vehicleId?: string;
}

export class UploadFleetVehicleDocumentDto {
  @ApiProperty({ enum: VEHICLE_DOC_TYPES })
  @IsIn([...VEHICLE_DOC_TYPES])
  type!: string;

  @ApiProperty()
  @IsString()
  @MinLength(8)
  fileBase64!: string;

  @ApiProperty()
  @IsString()
  mime!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  originalName?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsDateString()
  expiresAt?: string;
}
