import { publicErrorMessage } from './public-error.filter';

describe('public error filter', () => {
  it('hides internal messages on 500', () => {
    expect(publicErrorMessage(500, 'ECONNREFUSED mysql')).toBe('Something went wrong. Please try again.');
    expect(publicErrorMessage(400, 'Pickup must be in the future')).toBe('Pickup must be in the future');
  });
});
