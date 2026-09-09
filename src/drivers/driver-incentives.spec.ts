import { incentivePeriodKey, incentiveProgress, parseIncentiveCatalog } from './driver-incentives';

describe('driver incentives', () => {
  it('parses targets, bonus and validity', () => {
    const [row] = parseIncentiveCatalog(
      JSON.stringify([
        {
          id: 'five',
          title: '5 trips',
          amountPaise: 10000,
          targetTrips: 5,
          targetEarningsPaise: 50000,
          period: 'day',
          validFrom: '2026-09-01',
          validTo: '2026-09-30',
        },
      ]),
    );
    expect(row.bonusPaise).toBe(10000);
    expect(row.targetEarningsPaise).toBe(50000);
    const progress = incentiveProgress({
      row,
      trips: 3,
      earningsPaise: 40000,
      earnedPaise: 0,
      now: new Date('2026-09-07T12:00:00+05:30'),
    });
    expect(progress.progress).toBe(0.6);
    expect(progress.complete).toBe(false);
    expect(progress.valid).toBe(true);
  });

  it('keys daily periods in IST', () => {
    expect(incentivePeriodKey('day', new Date('2026-09-07T01:00:00+05:30'))).toBe('D-2026-09-07');
  });
});
