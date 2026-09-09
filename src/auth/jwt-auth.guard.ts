import {
  CanActivate,
  ExecutionContext,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';

@Injectable()
export class JwtAuthGuard implements CanActivate {
  constructor(private readonly jwt: JwtService) {}

  canActivate(context: ExecutionContext): boolean {
    const request = context.switchToHttp().getRequest<{
      headers: { authorization?: string };
      user?: { sub: string; role: string };
    }>();
    const header = request.headers.authorization ?? '';
    const token = header.startsWith('Bearer ') ? header.slice(7) : '';
    if (!token) {
      throw new UnauthorizedException('Missing bearer token');
    }
    try {
      request.user = this.jwt.verify<{ sub: string; role: string }>(token);
      return true;
    } catch {
      throw new UnauthorizedException('Invalid token');
    }
  }
}
