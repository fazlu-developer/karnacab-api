import { Type } from 'class-transformer';
import {
  IsDateString,
  IsIn,
  IsInt,
  IsOptional,
  IsString,
  MaxLength,
  Min,
  MinLength,
} from 'class-validator';
import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { AD_ALLOWED_PLACEMENTS, AD_BLOCKED_PLACEMENTS, AD_CATEGORIES, AD_TYPES } from '../ad.policy';

export class CreateAdCampaignDto {
  @ApiProperty()
  @IsString()
  @MinLength(2)
  @MaxLength(160)
  businessName!: string;

  @ApiProperty()
  @IsString()
  @MinLength(2)
  @MaxLength(160)
  title!: string;

  @ApiProperty({ enum: AD_CATEGORIES })
  @IsIn([...AD_CATEGORIES])
  category!: (typeof AD_CATEGORIES)[number];

  @ApiPropertyOptional({ enum: AD_TYPES })
  @IsOptional()
  @IsIn([...AD_TYPES])
  campaignType?: (typeof AD_TYPES)[number];

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(2000)
  businessInfo?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(80)
  targetCity?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  stateId?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  districtId?: number;

  @ApiProperty()
  @IsDateString()
  startsOn!: string;

  @ApiProperty()
  @IsDateString()
  endsOn!: string;

  @ApiProperty({ description: 'Budget in rupees' })
  @Type(() => Number)
  @IsInt()
  @Min(1)
  budgetRupees!: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(500)
  ctaUrl?: string;
}

export class PatchAdCampaignDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(160)
  businessName?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(160)
  title?: string;

  @ApiPropertyOptional({ enum: AD_CATEGORIES })
  @IsOptional()
  @IsIn([...AD_CATEGORIES])
  category?: (typeof AD_CATEGORIES)[number];

  @ApiPropertyOptional({ enum: AD_TYPES })
  @IsOptional()
  @IsIn([...AD_TYPES])
  campaignType?: (typeof AD_TYPES)[number];

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(2000)
  businessInfo?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(80)
  targetCity?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  stateId?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  districtId?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsDateString()
  startsOn?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsDateString()
  endsOn?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  budgetRupees?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(500)
  ctaUrl?: string;

  @ApiPropertyOptional({ enum: ['draft', 'pending', 'paused'] })
  @IsOptional()
  @IsIn(['draft', 'pending', 'paused'])
  status?: 'draft' | 'pending' | 'paused';
}

export class UploadAdBannerDto {
  @ApiProperty()
  @IsString()
  @MinLength(8)
  fileBase64!: string;

  @ApiProperty()
  @IsString()
  mime!: string;
}

export class ReviewAdDto {
  @ApiProperty({ enum: ['approved', 'rejected'] })
  @IsIn(['approved', 'rejected'])
  status!: 'approved' | 'rejected';

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(255)
  reason?: string;
}

export class ServeAdsQueryDto {
  @ApiProperty({ enum: AD_ALLOWED_PLACEMENTS })
  @IsIn([...AD_ALLOWED_PLACEMENTS, ...AD_BLOCKED_PLACEMENTS])
  placement!: string;

  @ApiPropertyOptional({ description: 'Rejected. Targeting uses the authenticated user district.' })
  @IsOptional()
  districtId?: string;

  @ApiPropertyOptional()
  @IsOptional()
  city?: string;
}

export class AdEventDto {
  @ApiProperty({ enum: AD_ALLOWED_PLACEMENTS })
  @IsIn([...AD_ALLOWED_PLACEMENTS])
  placement!: string;
}
