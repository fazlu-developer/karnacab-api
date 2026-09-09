import { IsIn, IsOptional, IsString } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';
import { LifecycleAction } from '../../ride-engine/booking-lifecycle';

const ACTIONS = ['confirm', 'search', 'arriving', 'arrived', 'start', 'complete', 'cancel'] as const;

export class BookingLifecycleDto {
  @ApiProperty({ enum: ACTIONS })
  @IsIn(ACTIONS)
  action!: LifecycleAction;

  @ApiProperty({ required: false, description: 'Customer start PIN for start, or completion PIN for complete' })
  @IsOptional()
  @IsString()
  otp?: string;
}
