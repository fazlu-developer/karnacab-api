import { Type } from 'class-transformer';
import { IsNumber, Min } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';

export class DriverWithdrawDto {
  @ApiProperty({ example: 500 })
  @Type(() => Number)
  @IsNumber()
  @Min(1)
  amountRupees!: number;
}
