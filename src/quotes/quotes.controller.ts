import { Body, Controller, Post } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { RideQuoteDto } from './dto/ride-quote.dto';
import { RideOptionsDto } from './dto/ride-options.dto';
import { QuotesService } from './quotes.service';

@ApiTags('quotes')
@Controller('quotes')
export class QuotesController {
  constructor(private readonly quotes: QuotesService) {}

  @Post('ride')
  @ApiOperation({ summary: 'Estimate a ride from configurable fare rules' })
  estimate(@Body() dto: RideQuoteDto) {
    return this.quotes.ride(dto);
  }

  @Post('options')
  @ApiOperation({ summary: 'Quote every active vehicle category for a distance' })
  options(@Body() dto: RideOptionsDto) {
    return this.quotes.options(dto);
  }
}
