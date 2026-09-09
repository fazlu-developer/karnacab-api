import { Type } from 'class-transformer';
import {
  IsArray,
  IsBoolean,
  IsEnum,
  IsInt,
  IsNumber,
  IsOptional,
  IsString,
  Max,
  Min,
  ValidateNested,
} from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';
import { RideProduct } from '@prisma/client';
import { QuoteStopDto } from './ride-quote.dto';

export class RideOptionsDto {
  @ApiProperty({ enum: RideProduct })
  @IsEnum(RideProduct)
  product!: RideProduct;

  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  distanceKm!: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  night?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsInt()
  districtId?: number;

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

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  polyline?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  hours?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsInt()
  @Min(0)
  stopCount?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  roundTrip?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsInt()
  @Min(0)
  waitMinutes?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsInt()
  @Min(0)
  tollPaise?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsInt()
  @Min(0)
  parkingPaise?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  extraHours?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  nightStayNights?: number;

  @ApiProperty({ required: false, type: [QuoteStopDto] })
  @IsOptional()
  @IsArray()
  @ValidateNested({ each: true })
  @Type(() => QuoteStopDto)
  stops?: QuoteStopDto[];
}
