import { Type } from 'class-transformer';
import { IsBoolean, IsInt, IsObject, IsOptional, IsString, Min, MinLength, ValidateNested } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';

export class CmsPromoDto {
  @ApiProperty()
  @IsString()
  title!: string;

  @ApiProperty()
  @IsString()
  subtitle!: string;

  @ApiProperty()
  @IsString()
  cta!: string;

  @ApiProperty()
  @IsString()
  href!: string;
}

export class PatchCmsPageDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  @MinLength(2)
  title?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  eyebrow?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  seoTitle?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  seoDescription?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  @MinLength(8)
  lede?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsObject()
  body?: Record<string, unknown>;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  published?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  navLabel?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  sortOrder?: number;
}

export class PatchCmsSiteDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  name?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  tagline?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  footerBlurb?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  contactEmail?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  contactPhone?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  canonicalHost?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  defaultSeoTitle?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  defaultSeoDescription?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  ogImage?: string;

  @ApiProperty({ required: false, type: CmsPromoDto })
  @IsOptional()
  @ValidateNested()
  @Type(() => CmsPromoDto)
  promo?: CmsPromoDto;
}
