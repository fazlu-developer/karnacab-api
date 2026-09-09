import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import {
  IsBoolean,
  IsDateString,
  IsIn,
  IsInt,
  IsOptional,
  IsString,
  Matches,
  MaxLength,
  MinLength,
} from 'class-validator';
import { DOC_TYPES, KYC_STATUSES } from '../kyc.types';

export class UploadDriverDocumentDto {
  @ApiProperty({ enum: DOC_TYPES })
  @IsIn([...DOC_TYPES])
  type!: string;

  @ApiProperty({ description: 'Base64 file body (no data: prefix required)' })
  @IsString()
  @MinLength(8)
  fileBase64!: string;

  @ApiProperty({ example: 'image/jpeg' })
  @IsString()
  mime!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(180)
  originalName?: string;

  @ApiPropertyOptional({ example: '2028-04-12' })
  @IsOptional()
  @IsDateString()
  expiresAt?: string;
}

export class PatchDriverProfileDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MinLength(2)
  firstName?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  lastName?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsDateString()
  dateOfBirth?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsIn(['MALE', 'FEMALE', 'OTHER'])
  gender?: 'MALE' | 'FEMALE' | 'OTHER';

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MinLength(8)
  address?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  city?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  state?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Matches(/^\d{6}$/)
  pinCode?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @IsIn(['PAN', 'Aadhaar', 'Driving Licence'])
  idType?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  idNumber?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  licenseNo?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  email?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsBoolean()
  termsAccepted?: boolean;

  @ApiPropertyOptional()
  @IsOptional()
  districtId?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(120)
  emergencyName?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(20)
  emergencyPhone?: string;
}

export class PatchDriverVehicleDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsIn(['BIKE', 'AUTO', 'E_RICKSHAW', 'MINI', 'SEDAN', 'SUV', 'TRAVELLER'])
  category?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  registrationNo?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  brand?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  model?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsInt()
  year?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  color?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  fuel?: string;
}

export class PatchDriverBankDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  accountHolder?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  accountNumber?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @Matches(/^[A-Z]{4}0[A-Z0-9]{6}$/)
  ifsc?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  upiId?: string;
}

export class SubmitKycDto {
  @ApiProperty()
  @IsBoolean()
  termsAccepted!: boolean;
}

export class ReviewDocumentDto {
  @ApiProperty({ enum: ['verified', 'rejected'] })
  @IsIn(['verified', 'rejected'])
  status!: 'verified' | 'rejected';

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(500)
  rejectionReason?: string;
}

export class ReviewKycDto {
  @ApiProperty({ enum: KYC_STATUSES })
  @IsIn(['verified', 'rejected', 'under_review'])
  status!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @MaxLength(500)
  reason?: string;
}
