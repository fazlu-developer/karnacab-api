import {
  firstName,
  indianMobile,
  mapsUrl,
  phoneLast4,
  plateHint,
} from './safety.privacy';

describe('safety privacy helpers', () => {
  it('keeps first names only', () => {
    expect(firstName('Rakesh Kumar')).toBe('Rakesh');
    expect(firstName('  Fazlu  ')).toBe('Fazlu');
  });

  it('masks phone and plate', () => {
    expect(phoneLast4('+91 9999999999')).toBe('9999');
    expect(plateHint('BR01AB1234')).toBe('****1234');
  });

  it('accepts Indian mobiles only', () => {
    expect(indianMobile('9999999999')).toBe('9999999999');
    expect(indianMobile('12345')).toBeNull();
  });

  it('builds a maps URL without extra identity', () => {
    expect(mapsUrl(25.6, 85.1)).toBe('https://maps.google.com/?q=25.6,85.1');
  });
});
