import { Body, Controller, Get, Param, Patch, Post, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { CorporateService } from './corporate.service';
import {
  AddCorporateEmployeeDto,
  CorporateSupportDto,
  PayCorporateInvoiceDto,
  UpsertCorporateAccountDto,
} from './dto/corporate.dto';

const CORP = [UserRole.CUSTOMER, UserRole.CORPORATE, UserRole.ADMIN, UserRole.SUPER_ADMIN] as const;

@ApiTags('corporate')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Roles(...CORP)
@Controller('corporate')
export class CorporateController {
  constructor(private readonly corporate: CorporateService) {}

  @Get()
  @ApiOperation({ summary: 'Basic corporate dashboard' })
  dashboard(@CurrentActor() actor: Actor) {
    return this.corporate.dashboard(actor);
  }

  @Patch('account')
  @ApiOperation({ summary: 'Create or update company profile (GSTIN, contact)' })
  upsert(@CurrentActor() actor: Actor, @Body() dto: UpsertCorporateAccountDto) {
    return this.corporate.upsertAccount(actor, dto);
  }

  @Get('employees')
  @ApiOperation({ summary: 'List company employees' })
  employees(@CurrentActor() actor: Actor) {
    return this.corporate.employees(actor);
  }

  @Post('employees')
  @ApiOperation({ summary: 'Add an employee to the company roster' })
  addEmployee(@CurrentActor() actor: Actor, @Body() dto: AddCorporateEmployeeDto) {
    return this.corporate.addEmployee(actor, dto);
  }

  @Get('invoices')
  @ApiOperation({ summary: 'GST invoices for monthly billing' })
  invoices(@CurrentActor() actor: Actor) {
    return this.corporate.invoices(actor);
  }

  @Post('invoices/generate')
  @ApiOperation({ summary: 'Issue this month GST invoice from account jobs' })
  generate(@CurrentActor() actor: Actor) {
    return this.corporate.generateMonthlyInvoice(actor);
  }

  @Post('invoices/:id/pay')
  @ApiOperation({ summary: 'Pay a corporate GST invoice' })
  pay(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: PayCorporateInvoiceDto) {
    return this.corporate.payInvoice(actor, BigInt(id), dto);
  }

  @Get('reports')
  @ApiOperation({ summary: 'Lightweight spend and booking report' })
  reports(@CurrentActor() actor: Actor) {
    return this.corporate.reports(actor);
  }

  @Post('support')
  @ApiOperation({ summary: 'Corporate support ticket' })
  support(@CurrentActor() actor: Actor, @Body() dto: CorporateSupportDto) {
    return this.corporate.support(actor, dto);
  }
}
