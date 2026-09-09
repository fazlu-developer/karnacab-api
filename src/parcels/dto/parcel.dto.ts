import { Type } from 'class-transformer';
import {
  IsBoolean,
  IsEnum,
  IsInt,
  IsNumber,
  IsOptional,
  IsString,
  Max,
  Min,
  ValidateIf,
} from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';
import { VehicleCategory } from '@prisma/client';

export class ParcelQuoteDto {
  @ApiProperty()
  @IsBoolean()
  biharLane!: boolean;

  @ApiProperty({ enum: VehicleCategory })
  @IsEnum(VehicleCategory)
  category!: VehicleCategory;

  @ApiProperty({ required: false })
  @IsOptional()
  @ValidateIf((dto: ParcelQuoteDto) => dto.pickupLat == null || dto.dropLat == null)
  @Type(() => Number)
  @IsNumber()
  @Min(0.1)
  distanceKm?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  @Min(-90)
  @Max(90)
  pickupLat?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  @Min(-180)
  @Max(180)
  pickupLng?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  @Min(-90)
  @Max(90)
  dropLat?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  @Min(-180)
  @Max(180)
  dropLng?: number;

  @ApiProperty()
  @Type(() => Number)
  @IsNumber()
  @Min(0.1)
  weightKg!: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  @Min(0)
  lengthCm?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  @Min(0)
  widthCm?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  @Min(0)
  heightCm?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  quantity?: number;
}

export class CreateParcelDto extends ParcelQuoteDto {
  @ApiProperty()
  @IsString()
  parcelType!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  description?: string;

  @ApiProperty()
  @IsString()
  pickupText!: string;

  @ApiProperty()
  @IsString()
  dropText!: string;

  @ApiProperty()
  @IsString()
  contactName!: string;

  @ApiProperty()
  @IsString()
  contactPhone!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  instructions?: string;

  @ApiProperty()
  @IsBoolean()
  complianceConfirmed!: boolean;
}

export class PayParcelDto {
  @ApiProperty()
  @IsString()
  method!: string;
}

export class ParcelLifecycleDto {
  @ApiProperty()
  @IsString()
  action!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  otp?: string;
}
