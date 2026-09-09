import { FranchiseStatus } from '@prisma/client';
import {
  canTransition,
  exclusiveSeatKey,
  holdsExclusiveSeat,
  otherActiveBlocks,
} from './franchise-status';

describe('franchise exclusive district seat', () => {
  it('holds the unique seat only while ACTIVE', () => {
    expect(holdsExclusiveSeat(FranchiseStatus.ACTIVE)).toBe(true);
    expect(holdsExclusiveSeat(FranchiseStatus.APPROVED)).toBe(false);
    expect(holdsExclusiveSeat(FranchiseStatus.APPLIED)).toBe(false);
    expect(exclusiveSeatKey(26)).toBe('26');
  });

  it('blocks a second active assignment for the same district', () => {
    expect(otherActiveBlocks(9n, 9n)).toBe(false);
    expect(otherActiveBlocks(9n, 12n)).toBe(true);
    expect(otherActiveBlocks(null, 12n)).toBe(false);
  });

  it('follows application → review → approved → active → terminal statuses', () => {
    expect(canTransition('APPLIED', 'UNDER_REVIEW')).toBe(true);
    expect(canTransition('UNDER_REVIEW', 'APPROVED')).toBe(true);
    expect(canTransition('APPROVED', 'ACTIVE')).toBe(true);
    expect(canTransition('ACTIVE', 'SUSPENDED')).toBe(true);
    expect(canTransition('ACTIVE', 'EXPIRED')).toBe(true);
    expect(canTransition('ACTIVE', 'TERMINATED')).toBe(true);
    expect(canTransition('APPLIED', 'ACTIVE')).toBe(false);
    expect(canTransition('TERMINATED', 'ACTIVE')).toBe(false);
  });
});
