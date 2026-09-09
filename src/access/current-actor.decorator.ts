import { createParamDecorator, ExecutionContext } from '@nestjs/common';
import { Actor } from './territory';

export const CurrentActor = createParamDecorator(
  (_data: unknown, context: ExecutionContext): Actor => {
    return context.switchToHttp().getRequest<{ actor: Actor }>().actor;
  },
);
