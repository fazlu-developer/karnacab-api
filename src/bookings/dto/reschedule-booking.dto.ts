import { IsISO8601 } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';

export class RescheduleBookingDto {
  @ApiProperty()
  @IsISO8601()
  scheduledAt!: string;
}
