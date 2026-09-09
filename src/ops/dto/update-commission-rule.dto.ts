import { Type } from 'class-transformer';
import { IsBoolean, IsIn, IsNumber, IsOptional, Max, Min } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';

export class UpdateCommissionRuleDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  @Min(0)
  @Max(100)
  percent?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  onBaseFare?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  onGst?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  onToll?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  onParking?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  onWaiting?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  onDiscount?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  onOther?: boolean;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsBoolean()
  active?: boolean;
}

export class ReviewWithdrawalDto {
  @ApiProperty({ enum: ['paid', 'rejected'] })
  @IsIn(['paid', 'rejected'])
  status!: 'paid' | 'rejected';
}
