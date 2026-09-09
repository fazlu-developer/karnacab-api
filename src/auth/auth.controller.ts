import { Body, Controller, Get, Patch, Post, Req, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { AuthService } from './auth.service';
import {
  CompleteProfileDto,
  DriverRegisterDto,
  LoginDto,
  RegisterDto,
  RequestOtpDto,
  UpdateLocationDto,
  VerifyOtpDto,
} from './dto/auth.dto';
import { JwtAuthGuard } from './jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { UserRole } from '@prisma/client';
import { OPERATOR_LOGIN_ROLES } from '../access/territory';

@ApiTags('auth')
@Controller('auth')
export class AuthController {
  constructor(private readonly auth: AuthService) {}

  @Post('register')
  @ApiOperation({ summary: 'Register a customer account' })
  register(@Body() dto: RegisterDto) {
    return this.auth.register(dto);
  }

  @Post('register-advertiser')
  @ApiOperation({ summary: 'Register a KarnaCab Ads advertiser account' })
  registerAdvertiser(@Body() dto: RegisterDto) {
    return this.auth.registerAdvertiser(dto);
  }

  @Post('register-driver')
  @ApiOperation({ summary: 'Register a driver / captain account' })
  registerDriver(@Body() dto: DriverRegisterDto) {
    return this.auth.registerDriver(dto);
  }

  @Post('login')
  @ApiOperation({ summary: 'Login with email and password' })
  login(@Body() dto: LoginDto) {
    return this.auth.login(dto);
  }

  @Post('driver/login')
  @ApiOperation({ summary: 'Driver app login' })
  driverLogin(@Body() dto: LoginDto) {
    return this.auth.login(dto, UserRole.DRIVER);
  }

  @Post('advertiser/login')
  @ApiOperation({ summary: 'Advertiser app login' })
  advertiserLogin(@Body() dto: LoginDto) {
    return this.auth.login(dto, UserRole.ADVERTISER);
  }

  @Post('operator/login')
  @ApiOperation({
    summary: 'Email/password login for fleet, district, state, franchise, corporate, and admin',
  })
  operatorLogin(@Body() dto: LoginDto) {
    return this.auth.login(dto, OPERATOR_LOGIN_ROLES);
  }

  @Post('otp/request')
  @ApiOperation({ summary: 'Send a login OTP to an Indian mobile number' })
  requestOtp(@Body() dto: RequestOtpDto) {
    return this.auth.requestOtp(dto.phone);
  }

  @Post('otp/verify')
  @ApiOperation({ summary: 'Verify mobile OTP and issue a customer JWT' })
  verifyOtp(@Body() dto: VerifyOtpDto) {
    return this.auth.verifyOtp(dto.phone, dto.code);
  }

  @Post('driver/otp/verify')
  @ApiOperation({ summary: 'Verify mobile OTP and issue a driver JWT (KYC resume or new application)' })
  verifyDriverOtp(@Body() dto: VerifyOtpDto) {
    return this.auth.verifyDriverOtp(dto.phone, dto.code);
  }

  @Get('me')
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Current user' })
  me(@Req() request: { user: { sub: string } }) {
    return this.auth.me(BigInt(request.user.sub));
  }

  @Patch('profile')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Save customer name, email, date of birth, gender' })
  completeProfile(
    @Req() request: { user: { sub: string } },
    @Body() dto: CompleteProfileDto,
  ) {
    return this.auth.completeProfile(BigInt(request.user.sub), dto);
  }

  @Patch('location')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Save last known GPS location' })
  updateLocation(
    @Req() request: { user: { sub: string } },
    @Body() dto: UpdateLocationDto,
  ) {
    return this.auth.updateLocation(BigInt(request.user.sub), dto);
  }
}
