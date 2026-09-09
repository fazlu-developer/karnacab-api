import { IsEmail, IsEnum, IsOptional, IsString, MinLength } from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';
import { LeadType } from '@prisma/client';

export class CreateLeadDto {
  @ApiProperty({ enum: LeadType })
  @IsEnum(LeadType)
  type!: LeadType;

  @ApiProperty()
  @IsString()
  @MinLength(2)
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
  district?: string;

  @ApiProperty()
  @IsString()
  @MinLength(8)
  message!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  payload?: string;
}
