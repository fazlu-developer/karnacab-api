import { Body, Controller, Post } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { PrismaService } from '../prisma/prisma.service';
import { CreateLeadDto } from './dto/create-lead.dto';

@ApiTags('leads')
@Controller('leads')
export class LeadsController {
  constructor(private readonly prisma: PrismaService) {}

  @Post()
  @ApiOperation({ summary: 'Website or app lead (parcel, bulk, franchise, ads, support)' })
  async create(@Body() dto: CreateLeadDto) {
    const lead = await this.prisma.lead.create({ data: dto });
    return {
      id: lead.id.toString(),
      status: lead.status,
      type: lead.type,
    };
  }
}
