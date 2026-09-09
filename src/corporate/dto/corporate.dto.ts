import { IsEmail, IsOptional, IsString, MaxLength, MinLength, ValidateIf } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';

export class UpsertCorporateAccountDto {
  @ApiProperty()
  @IsString()
  companyName!: string;

  @ApiProperty({ required: false })
  @ValidateIf((_, value) => value != null && value !== '')
  @IsOptional()
  @IsString()
  @MinLength(15)
  @MaxLength(15)
  gstin?: string;

  @ApiProperty()
  @IsString()
  contactName!: string;

  @ApiProperty()
  @IsString()
  contactPhone!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsEmail()
  contactEmail?: string;
}

export class AddCorporateEmployeeDto {
  @ApiProperty()
  @IsString()
  name!: string;

  @ApiProperty()
  @IsString()
  phone!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsEmail()
  email?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  department?: string;
}

export class CorporateSupportDto {
  @ApiProperty()
  @IsString()
  message!: string;
}

export class PayCorporateInvoiceDto {
  @ApiProperty()
  @IsString()
  method!: string;
}
