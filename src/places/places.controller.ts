import { Body, Controller, Delete, Get, Param, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CreatePlaceDto } from '../auth/dto/auth.dto';
import { GoogleMapsService } from './google-maps.service';
import { PlacesService } from './places.service';

@ApiTags('places')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('places')
export class PlacesController {
  constructor(
    private readonly places: PlacesService,
    private readonly google: GoogleMapsService,
  ) {}

  @Get('recent')
  @ApiOperation({ summary: 'Recent destinations for the signed-in customer' })
  recent(@Req() request: { user: { sub: string } }) {
    return this.places.recent(BigInt(request.user.sub));
  }

  @Get('saved')
  @ApiOperation({ summary: 'Saved locations (home, work, family addresses)' })
  saved(@Req() request: { user: { sub: string } }) {
    return this.places.saved(BigInt(request.user.sub));
  }

  @Post('saved')
  @ApiOperation({ summary: 'Save a location for later booking' })
  savePlace(
    @Req() request: { user: { sub: string } },
    @Body() dto: CreatePlaceDto,
  ) {
    return this.places.saveNamed(BigInt(request.user.sub), dto, 'SAVED');
  }

  @Post('recent')
  @ApiOperation({ summary: 'Save a searched destination' })
  save(
    @Req() request: { user: { sub: string } },
    @Body() dto: CreatePlaceDto,
  ) {
    return this.places.saveRecent(BigInt(request.user.sub), dto);
  }

  @Delete(':id')
  @ApiOperation({ summary: 'Remove a saved or recent place' })
  remove(@Req() request: { user: { sub: string } }, @Param('id') id: string) {
    return this.places.remove(BigInt(request.user.sub), BigInt(id));
  }

  @Get('autocomplete')
  @ApiOperation({ summary: 'Google Places autocomplete (India)' })
  async autocomplete(@Query('q') q: string, @Query('types') types?: string) {
    const suggestions = await this.google.autocomplete((q ?? '').trim(), types);
    return { suggestions };
  }

  @Get('details')
  @ApiOperation({ summary: 'Google Place details with lat/lng' })
  details(@Query('placeId') placeId: string) {
    return this.google.details(placeId);
  }

  @Get('directions')
  @ApiOperation({ summary: 'Driving distance, duration, polyline (optional waypoints)' })
  directions(
    @Query('originLat') originLat: string,
    @Query('originLng') originLng: string,
    @Query('destLat') destLat: string,
    @Query('destLng') destLng: string,
    @Query('waypoints') waypoints?: string,
  ) {
    const stops = (waypoints ?? '')
      .split('|')
      .map((row) => row.split(',').map(Number))
      .filter((pair) => pair.length === 2 && Number.isFinite(pair[0]) && Number.isFinite(pair[1]))
      .map(([lat, lng]) => ({ lat, lng }));
    return this.google.directions(
      Number(originLat),
      Number(originLng),
      Number(destLat),
      Number(destLng),
      stops,
    );
  }
}
