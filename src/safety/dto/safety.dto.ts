import { Type } from 'class-transformer';
import {
  IsIn,
  IsNumber,
  IsOptional,
  IsString,
  MaxLength,
  MinLength,
} from 'class-validator';
import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import {
  SAFETY_INCIDENT_STATUSES,
  SAFETY_INCIDENT_TYPES,
  SOS_KINDS,
} from '../safety.privacy';

export class EmergencyContactDto {
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

export class SafetySosDto {
  @ApiPropertyOptional({ enum: SOS_KINDS })
  @IsOptional()
  @IsIn([...SOS_KINDS])
  kind?: (typeof SOS_KINDS)[number];

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  bookingId?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  lat?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => Number)
  @IsNumber()
  lng?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(1000)
  description?: string;
}

export class SafetyShareDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  bookingId?: string;
}

export class SafetyTicketDto {
  @ApiProperty({ enum: SAFETY_INCIDENT_TYPES })
  @IsIn(['complaint', 'lost_found', 'support'])
  type!: 'complaint' | 'lost_found' | 'support';

  @ApiProperty()
  @IsString()
  @MinLength(8)
  @MaxLength(1000)
  description!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  bookingId?: string;
}

export class ReviewIncidentDto {
  @ApiProperty({ enum: SAFETY_INCIDENT_STATUSES })
  @IsIn([...SAFETY_INCIDENT_STATUSES])
  status!: (typeof SAFETY_INCIDENT_STATUSES)[number];

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(1000)
  adminNote?: string;
}
