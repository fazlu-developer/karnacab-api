import { ApiProperty } from '@nestjs/swagger';
import { IsBoolean, IsIn } from 'class-validator';

export class DriverOnlineDto {
  @ApiProperty()
  @IsBoolean()
  online!: boolean;
}

export class DriverDutyDto {
  @ApiProperty({ enum: ['online', 'offline', 'busy'] })
  @IsIn(['online', 'offline', 'busy'])
  status!: 'online' | 'offline' | 'busy';
}
