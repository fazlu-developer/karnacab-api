import { Type } from 'class-transformer';
import {
  IsDateString,
  IsIn,
  IsInt,
  IsNumber,
  IsOptional,
  IsString,
  MaxLength,
  Min,
  MinLength,
} from 'class-validator';
import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { FRANCHISE_DOC_TYPES, FRANCHISE_KINDS, FRANCHISE_STATUSES } from '../franchise-status';

export class ApplyFranchiseDto {
  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  districtId!: number;

  @ApiProperty({ enum: FRANCHISE_KINDS })
  @IsIn([...FRANCHISE_KINDS])
  kind!: (typeof FRANCHISE_KINDS)[number];

  @ApiProperty()
  @IsString()
  @MinLength(2)
  @MaxLength(160)
  tradeName!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(20)
  gstin?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(20)
  pan?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(20)
  contactPhone?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  feeAmountPaise?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(500)
  notes?: string;

  @ApiPropertyOptional({ description: 'Admin/state head only: create on behalf of this user' })
  @IsOptional()
  @IsString()
  ownerUserId?: string;
}

export class PatchFranchiseTerritoryDto {
  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  districtId!: number;
}

export class FranchiseLifecycleDto {
  @ApiProperty({ enum: FRANCHISE_STATUSES })
  @IsIn([...FRANCHISE_STATUSES])
  status!: (typeof FRANCHISE_STATUSES)[number];

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(500)
  reason?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsDateString()
  startsOn?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsDateString()
  endsOn?: string;
}

export class SignFranchiseAgreementDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(32)
  version?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsDateString()
  expiresAt?: string;
}

export class CreateFranchiseFeeDto {
  @ApiProperty()
  @IsIn(['application', 'annual', 'renewal'])
  kind!: 'application' | 'annual' | 'renewal';

  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  amountPaise!: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsDateString()
  dueOn?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  note?: string;
}

export class PatchFranchiseFeeDto {
  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  commissionPercent?: number;
}

export class UploadFranchiseDocumentDto {
  @ApiProperty({ enum: FRANCHISE_DOC_TYPES })
  @IsIn([...FRANCHISE_DOC_TYPES])
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

export class CreateFranchiseRenewalDto {
  @ApiProperty()
  @IsDateString()
  periodStart!: string;

  @ApiProperty()
  @IsDateString()
  periodEnd!: string;
}

export class FranchiseHierarchyQueryDto {
  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  districtId?: number;
}
