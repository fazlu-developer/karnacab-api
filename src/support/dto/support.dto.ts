import { Type } from 'class-transformer';
import { IsIn, IsOptional, IsString, MaxLength, MinLength } from 'class-validator';
import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import {
  SUPPORT_CATEGORIES,
  SUPPORT_KINDS,
  SUPPORT_PRIORITIES,
  SUPPORT_STATUSES,
} from '../../notify/notify.catalog';

export class CreateSupportTicketDto {
  @ApiProperty()
  @IsString()
  @MinLength(4)
  @MaxLength(160)
  subject!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MinLength(4)
  @MaxLength(2000)
  message?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MinLength(4)
  @MaxLength(2000)
  description?: string;

  @ApiPropertyOptional({ enum: SUPPORT_KINDS })
  @IsOptional()
  @IsIn([...SUPPORT_KINDS])
  kind?: (typeof SUPPORT_KINDS)[number];

  @ApiPropertyOptional({ enum: SUPPORT_CATEGORIES })
  @IsOptional()
  @IsIn([...SUPPORT_CATEGORIES])
  category?: (typeof SUPPORT_CATEGORIES)[number];

  @ApiPropertyOptional({ enum: SUPPORT_PRIORITIES })
  @IsOptional()
  @IsIn([...SUPPORT_PRIORITIES])
  priority?: (typeof SUPPORT_PRIORITIES)[number];

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  bookingId?: string;
}

export class SupportMessageDto {
  @ApiProperty()
  @IsString()
  @MinLength(2)
  @MaxLength(2000)
  body!: string;
}

export class ResolveTicketDto {
  @ApiProperty({ enum: SUPPORT_STATUSES })
  @IsIn([...SUPPORT_STATUSES])
  status!: (typeof SUPPORT_STATUSES)[number];

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(2000)
  message?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(2000)
  resolution?: string;
}

export class AssignTicketDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  agentId?: string;
}

export class SupportAttachmentDto {
  @ApiProperty()
  @IsString()
  @MinLength(8)
  fileBase64!: string;

  @ApiProperty()
  @IsString()
  mime!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(160)
  fileName?: string;
}

export class RegisterDeviceDto {
  @ApiProperty()
  @IsString()
  @MinLength(20)
  @MaxLength(4096)
  token!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  platform?: string;
}

export class AnnounceDto {
  @ApiProperty()
  @IsString()
  @MinLength(3)
  @MaxLength(160)
  title!: string;

  @ApiProperty()
  @IsString()
  @MinLength(3)
  @MaxLength(500)
  body!: string;

  @ApiProperty({ enum: ['customer', 'driver', 'ops'] })
  @IsIn(['customer', 'driver', 'ops'])
  audience!: 'customer' | 'driver' | 'ops';

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  kind?: string;
}

export class SupportQueueQueryDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  status?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Type(() => String)
  kind?: string;
}
