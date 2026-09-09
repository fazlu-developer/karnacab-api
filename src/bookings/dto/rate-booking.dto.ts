import { IsInt, IsOptional, IsString, Max, Min } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';

export class RateBookingDto {
  @ApiProperty({ minimum: 1, maximum: 5 })
  @IsInt()
  @Min(1)
  @Max(5)
  stars!: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  comment?: string;
}
