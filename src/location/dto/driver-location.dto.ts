import { IsNumber, IsOptional, IsString, Max, Min } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';

export class DriverLocationDto {
  @ApiProperty()
  @IsNumber()
  @Min(-90)
  @Max(90)
  lat!: number;

  @ApiProperty()
  @IsNumber()
  @Min(-180)
  @Max(180)
  lng!: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsNumber()
  heading?: number;

  @ApiProperty({ required: false, description: 'Speed in metres per second' })
  @IsOptional()
  @IsNumber()
  speed?: number;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  recordedAt?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  tripStatus?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  bookingId?: string;
}
