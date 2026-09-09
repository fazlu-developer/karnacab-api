import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsIn, IsOptional, IsString, MaxLength, MinLength } from 'class-validator';

export class DriverSupportTicketDto {
  @ApiProperty()
  @IsString()
  @MinLength(8)
  @MaxLength(2000)
  message!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  bookingId?: string;
}

export class DriverSosDto {
  @ApiPropertyOptional({ enum: ['emergency', 'karnacab', 'police', 'share'] })
  @IsOptional()
  @IsIn(['emergency', 'karnacab', 'police', 'share'])
  kind?: 'emergency' | 'karnacab' | 'police' | 'share';
}
