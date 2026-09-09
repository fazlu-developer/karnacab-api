import { Type } from 'class-transformer';
import {
  IsArray,
  IsEnum,
  IsInt,
  IsOptional,
  IsString,
  Min,
  ValidateNested,
} from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';
import { PackageStatus } from '@prisma/client';

export class TravelPackageQueryDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  category?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  destination?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  date?: string;
}

class ItineraryItemDto {
  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  day!: number;

  @ApiProperty()
  @IsString()
  title!: string;

  @ApiProperty()
  @IsString()
  detail!: string;
}

export class UpsertTravelPackageDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  districtId?: number;

  @ApiProperty()
  @IsString()
  category!: string;

  @ApiProperty()
  @IsString()
  title!: string;

  @ApiProperty()
  @IsString()
  destination!: string;

  @ApiProperty()
  @IsString()
  places!: string;

  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  durationHours!: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  durationLabel?: string;

  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  kmIncluded!: number;

  @ApiProperty()
  @IsString()
  vehicleLabel!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  driverLabel?: string;

  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  pricePaise!: number;

  @ApiProperty()
  @IsString()
  inclusions!: string;

  @ApiProperty()
  @IsString()
  exclusions!: string;

  @ApiProperty({ required: false, type: [ItineraryItemDto] })
  @IsOptional()
  @IsArray()
  @ValidateNested({ each: true })
  @Type(() => ItineraryItemDto)
  itinerary?: ItineraryItemDto[];

  @ApiProperty({ required: false, type: [String] })
  @IsOptional()
  @IsArray()
  @IsString({ each: true })
  gallery?: string[];

  @ApiProperty({ required: false, type: [String] })
  @IsOptional()
  @IsArray()
  @IsString({ each: true })
  availableDates?: string[];

  @ApiProperty({ required: false, enum: PackageStatus })
  @IsOptional()
  @IsEnum(PackageStatus)
  status?: PackageStatus;
}

export class PatchTravelPackageDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  category?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  title?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  destination?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  places?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  durationHours?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  durationLabel?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  kmIncluded?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  vehicleLabel?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  driverLabel?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  pricePaise?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  inclusions?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  exclusions?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsArray()
  itinerary?: ItineraryItemDto[];

  @ApiProperty({ required: false, type: [String] })
  @IsOptional()
  @IsArray()
  gallery?: string[];

  @ApiProperty({ required: false, type: [String] })
  @IsOptional()
  @IsArray()
  availableDates?: string[];

  @ApiProperty({ required: false, enum: PackageStatus })
  @IsOptional()
  @IsEnum(PackageStatus)
  status?: PackageStatus;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  districtId?: number;
}

export class BookTravelDto {
  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  packageId!: number;

  @ApiProperty({ example: '2026-10-10' })
  @IsString()
  travelDate!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  guests?: number;

  @ApiProperty()
  @IsString()
  contactName!: string;

  @ApiProperty()
  @IsString()
  contactPhone!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  notes?: string;
}

export class PayTravelDto {
  @ApiProperty()
  @IsString()
  method!: string;
}
