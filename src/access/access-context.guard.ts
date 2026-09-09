import {
  CanActivate,
  ExecutionContext,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common';
import { ScopeService } from './scope.service';
import { Actor } from './territory';

@Injectable()
export class AccessContextGuard implements CanActivate {
  constructor(private readonly scopes: ScopeService) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const request = context.switchToHttp().getRequest<{
      user?: { sub: string; role: string };
      actor?: Actor;
    }>();
    if (!request.user?.sub) {
      throw new UnauthorizedException('Missing bearer token');
    }
    request.actor = await this.scopes.resolve(BigInt(request.user.sub));
    request.user.role = request.actor.role;
    return true;
  }
}
