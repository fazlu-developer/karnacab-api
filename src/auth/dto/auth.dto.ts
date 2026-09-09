import {
  IsDateString,
  IsEmail,
  IsEnum,
  IsNumber,
  IsOptional,
  IsString,
  Matches,
  Max,
  Min,
  MinLength,
} from 'class-validator';
import { ApiProperty } from '@nestjs/swagger';
import { UserGender } from '@prisma/client';

export class RegisterDto {
  @ApiProperty()
  @IsString()
  name!: string;

  @ApiProperty()
  @IsEmail()
  email!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  phone?: string;

  @ApiProperty()
  @IsString()
  @MinLength(8)
  password!: string;
}

export class DriverRegisterDto {
  @ApiProperty()
  @IsString()
  name!: string;

  @ApiProperty()
  @IsEmail()
  email!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  phone?: string;

  @ApiProperty()
  @IsString()
  @MinLength(8)
  password!: string;

  @ApiProperty()
  @IsString()
  @MinLength(6)
  licenseNo!: string;
}

export class LoginDto {
  @ApiProperty()
  @IsEmail()
  email!: string;

  @ApiProperty()
  @IsString()
  password!: string;
}

export class RequestOtpDto {
  @ApiProperty({ example: '9876543210', description: '10-digit Indian mobile' })
  @IsString()
  @Matches(/^[6-9]\d{9}$/, {
    message: 'Enter a valid 10-digit Indian mobile number',
  })
  phone!: string;
}

export class VerifyOtpDto {
  @ApiProperty({ example: '9876543210' })
  @IsString()
  @Matches(/^[6-9]\d{9}$/, {
    message: 'Enter a valid 10-digit Indian mobile number',
  })
  phone!: string;

  @ApiProperty({ example: '123456' })
  @IsString()
  @Matches(/^\d{6}$/, { message: 'Enter the 6-digit verification code' })
  code!: string;
}

export class CompleteProfileDto {
  @ApiProperty({ example: 'Rakesh Kumar' })
  @IsString()
  @MinLength(2)
  name!: string;

  @ApiProperty({ example: 'rakesh@example.com' })
  @IsEmail()
  email!: string;

  @ApiProperty({ example: '1998-04-12' })
  @IsDateString()
  dateOfBirth!: string;

  @ApiProperty({ enum: UserGender })
  @IsEnum(UserGender)
  gender!: UserGender;
}

export class UpdateLocationDto {
  @ApiProperty({ example: 25.5941 })
  @IsNumber()
  @Min(-90)
  @Max(90)
  lat!: number;

  @ApiProperty({ example: 85.1376 })
  @IsNumber()
  @Min(-180)
  @Max(180)
  lng!: number;

  @ApiProperty({ example: 'Fraser Road, Patna, Bihar' })
  @IsString()
  @MinLength(3)
  address!: string;
}

export class CreatePlaceDto {
  @ApiProperty()
  @IsString()
  @MinLength(2)
  title!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  subtitle?: string;

  @ApiProperty()
  @IsString()
  @MinLength(3)
  address!: string;

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
}
