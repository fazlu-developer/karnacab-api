import { Body, Controller, Get, Headers, Param, Post, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiHeader, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { RequirePermissions } from '../access/roles.decorator';
import { Permission } from '../access/permissions';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { PaymentsService } from './payments.service';
import {
  CreatePaymentIntentDto,
  FailPaymentDto,
  PaymentWebhookDto,
  RefundPaymentDto,
} from './dto/payments.dto';

@ApiTags('payments')
@Controller('payments')
export class PaymentsController {
  constructor(private readonly payments: PaymentsService) {}

  @Get('catalog')
  @ApiOperation({ summary: 'Payment methods and server-side capture rules' })
  catalog() {
    return this.payments.catalog();
  }

  @Get('me')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.PaymentsRead)
  @ApiOperation({ summary: 'Transaction history' })
  history(@CurrentActor() actor: Actor) {
    return this.payments.history(actor);
  }

  @Get('invoices')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.PaymentsRead)
  @ApiOperation({ summary: 'Issued invoices (ride, parcel, travel, bulk, corporate)' })
  invoices(@CurrentActor() actor: Actor) {
    return this.payments.invoices(actor);
  }

  @Get('invoices/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.PaymentsRead)
  @ApiOperation({ summary: 'One invoice with related payments' })
  invoice(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.payments.oneInvoice(actor, BigInt(id));
  }

  @Post('intents')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.PaymentsWrite)
  @ApiOperation({
    summary: 'Start a payment. Remains pending until wallet ledger, cash confirm, or signed webhook.',
  })
  intent(@CurrentActor() actor: Actor, @Body() dto: CreatePaymentIntentDto) {
    return this.payments.initiate(actor, dto);
  }

  @Get(':id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.PaymentsRead)
  @ApiOperation({ summary: 'One payment / refund' })
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.payments.onePayment(actor, BigInt(id));
  }

  @Post(':id/confirm-cash')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.PaymentsWrite)
  @ApiOperation({ summary: 'Driver or ops confirms cash collected (server capture)' })
  confirmCash(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.payments.confirmCash(actor, BigInt(id));
  }

  @Post(':id/retry')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.PaymentsWrite)
  @ApiOperation({ summary: 'Retry a failed payment; creates a new pending attempt' })
  retry(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.payments.retry(actor, BigInt(id));
  }

  @Post(':id/fail')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.PaymentsWrite)
  @ApiOperation({ summary: 'Mark a pending payment failed (does not capture)' })
  fail(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: FailPaymentDto) {
    return this.payments.markFailed(actor, BigInt(id), dto);
  }

  @Post(':id/refund')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.PaymentsWrite)
  @ApiOperation({ summary: 'Full or partial refund; wallet refunds credit the ledger' })
  refund(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: RefundPaymentDto) {
    return this.payments.refund(actor, BigInt(id), dto);
  }
}

@ApiTags('payments')
@Controller('payments/webhooks')
export class PaymentWebhookController {
  constructor(private readonly payments: PaymentsService) {}

  @Post(':provider')
  @ApiOperation({ summary: 'Gateway webhook. Signature required. Client app success is ignored.' })
  @ApiHeader({ name: 'x-karnacab-webhook-signature', required: true })
  webhook(
    @Param('provider') provider: string,
    @Headers('x-karnacab-webhook-signature') signature: string | undefined,
    @Body() body: PaymentWebhookDto,
  ) {
    return this.payments.handleWebhook(provider, signature, body);
  }
}
