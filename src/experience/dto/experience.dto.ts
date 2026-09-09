import { Type } from 'class-transformer';
import { IsInt, IsOptional, IsString, MaxLength, Min, MinLength } from 'class-validator';
import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';

export class FamilyMemberDto {
  @ApiProperty()
  @IsString()
  @MinLength(2)
  @MaxLength(120)
  name!: string;

  @ApiProperty()
  @IsString()
  @MinLength(10)
  @MaxLength(20)
  phone!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(40)
  relation?: string;
}

export class PreviewCouponDto {
  @ApiProperty()
  @IsString()
  @MinLength(3)
  @MaxLength(32)
  code!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  farePaise?: number;

  @ApiPropertyOptional({ description: 'Rejected. Discount is quoted on the server.' })
  @IsOptional()
  @Type(() => Number)
  discountPaise?: number;
}
