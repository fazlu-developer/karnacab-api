import { Type } from 'class-transformer';
import { IsEnum, IsInt, IsNumber, IsOptional, IsString, Max, Min } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';
import { VehicleCategory } from '@prisma/client';

export class BulkQuoteDto {
  @ApiProperty()
  @IsString()
  eventKey!: string;

  @ApiProperty({ enum: VehicleCategory })
  @IsEnum(VehicleCategory)
  category!: VehicleCategory;

  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  vehicleCount!: number;
}

export class CreateBulkDto extends BulkQuoteDto {
  @ApiProperty()
  @IsString()
  pickupText!: string;

  @ApiProperty()
  @IsString()
  dropText!: string;

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
  pickupLng?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  dropLat?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  dropLng?: number;

  @ApiProperty({ example: '2026-10-20' })
  @IsString()
  eventDate!: string;

  @ApiProperty({ example: '09:30' })
  @IsString()
  eventTime!: string;

  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  passengers!: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  requirements?: string;
}

export class PayBulkDto {
  @ApiProperty()
  @IsString()
  method!: string;
}

export class BulkLifecycleDto {
  @ApiProperty()
  @IsString()
  action!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  quotePaise?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  assignmentNotes?: string;
}
