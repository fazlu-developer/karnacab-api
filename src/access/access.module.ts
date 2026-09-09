import { Global, Module } from '@nestjs/common';
import { AccessContextGuard } from './access-context.guard';
import { RolesGuard } from './roles.guard';
import { ScopeService } from './scope.service';

@Global()
@Module({
  providers: [RolesGuard, ScopeService, AccessContextGuard],
  exports: [RolesGuard, ScopeService, AccessContextGuard],
})
export class AccessModule {}
