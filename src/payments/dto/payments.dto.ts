import { Type } from 'class-transformer';
import { IsIn, IsInt, IsOptional, IsString, MaxLength, Min } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';
import { PAYMENT_METHODS } from '../payment.model';

export class CreatePaymentIntentDto {
  @ApiProperty({ enum: PAYMENT_METHODS })
  @IsString()
  method!: string;

  @ApiProperty({ required: false, enum: ['capture', 'advance', 'partial'] })
  @IsOptional()
  @IsIn(['capture', 'advance', 'partial'])
  intent?: 'capture' | 'advance' | 'partial';

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  amountPaise?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  bookingId?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  parcelId?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  travelBookingId?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  bulkBookingId?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  corporateInvoiceId?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  @MaxLength(255)
  note?: string;
}

export class RefundPaymentDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  amountPaise?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  @MaxLength(255)
  reason?: string;
}

export class FailPaymentDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  @MaxLength(40)
  code?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  @MaxLength(255)
  note?: string;
}

export class PaymentWebhookDto {
  @ApiProperty()
  @IsString()
  event!: string;

  @ApiProperty()
  @IsString()
  paymentRef!: string;

  @ApiProperty()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  amountPaise!: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  gatewayPaymentId?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  gatewayEventId?: string;
}
