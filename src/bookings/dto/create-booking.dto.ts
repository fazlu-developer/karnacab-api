import { Type } from 'class-transformer';
import {
  IsArray,
  IsBoolean,
  IsEnum,
  IsInt,
  IsISO8601,
  IsNumber,
  IsOptional,
  IsString,
  Max,
  Min,
  ValidateNested,
} from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';
import { RideProduct, VehicleCategory } from '@prisma/client';

class StopDto {
  @ApiProperty()
  @IsString()
  label!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  lat?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  lng?: number;
}

export class CreateBookingDto {
  @ApiProperty({ enum: RideProduct })
  @IsEnum(RideProduct)
  product!: RideProduct;

  @ApiProperty({ enum: VehicleCategory })
  @IsEnum(VehicleCategory)
  category!: VehicleCategory;

  @ApiProperty()
  @IsString()
  pickupText!: string;

  @ApiProperty()
  @IsString()
  dropText!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsNumber()
  @Min(-90)
  @Max(90)
  pickupLat?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsNumber()
  @Min(-180)
  @Max(180)
  pickupLng?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsNumber()
  @Min(-90)
  @Max(90)
  dropLat?: number;

  @ApiProperty({ required: false })
  @IsOptional()
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
  @IsNumber()
  @Min(0.1)
  distanceKm?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsNumber()
  districtId?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  draft?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsISO8601()
  scheduledAt?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsISO8601()
  returnAt?: string;

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
  waitMinutes?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  night?: boolean;

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
  @IsInt()
  @Min(0)
  stopCount?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  roundTrip?: boolean;

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

  @ApiProperty({ required: false, type: [StopDto] })
  @IsOptional()
  @IsArray()
  @ValidateNested({ each: true })
  @Type(() => StopDto)
  stops?: StopDto[];

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  flightNumber?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  trainNumber?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  terminal?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  bookForOther?: boolean;

  @ApiProperty({ required: false, description: 'Actual passenger name when booking for someone else' })
  @IsOptional()
  @IsString()
  passengerName?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  passengerPhone?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  instructions?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  familyMemberId?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  couponCode?: string;

  @ApiProperty({ required: false, description: 'Rejected. Discount is quoted on the server from couponCode.' })
  @IsOptional()
  @Type(() => Number)
  couponDiscountPaise?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  discountPaise?: number;
}
