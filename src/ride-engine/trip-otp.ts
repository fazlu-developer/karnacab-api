import { ForbiddenException } from '@nestjs/common';

export function assertTripPin(kind: 'start' | 'end', stored?: string | null, provided?: string) {
  if (!stored) {
    throw new ForbiddenException(
      kind === 'start' ? 'Start PIN is not issued for this trip' : 'Completion PIN is not issued for this trip',
    );
  }
  const pin = provided?.trim() ?? '';
  if (!pin) {
    throw new ForbiddenException(
      kind === 'start' ? 'Enter the customer start PIN to begin the trip' : 'Enter the customer completion PIN to finish the trip',
    );
  }
  if (pin !== stored) {
    throw new ForbiddenException(kind === 'start' ? 'Invalid start PIN' : 'Invalid completion PIN');
  }
}
