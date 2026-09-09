import { driverFacingPeople, resolveRidePeople } from './ride-people';

describe('ride people privacy', () => {
  it('keeps booker and passenger distinct for someone else', () => {
    const people = resolveRidePeople({
      bookedForOther: true,
      passengerName: 'Aisha',
      passengerPhone: '9876543210',
      instructions: 'Call on arrival',
      bookerName: 'Fazlu Rahman',
      bookerPhone: '9999999999',
    });
    expect(people.bookedForOther).toBe(true);
    expect(people.passenger).toEqual({
      name: 'Aisha',
      phone: '9876543210',
      instructions: 'Call on arrival',
      isBooker: false,
    });
    expect(people.booker.phoneLast4).toBe('9999');
  });

  it('hides booker and passenger phone from drivers until assigned', () => {
    const people = resolveRidePeople({
      bookedForOther: true,
      passengerName: 'Aisha',
      passengerPhone: '9876543210',
      instructions: 'Gate 2',
      bookerName: 'Fazlu',
      bookerPhone: '9999999999',
    });
    expect(driverFacingPeople(people, false)).toEqual({
      bookedForOther: true,
      passenger: { name: 'Aisha', phone: null, instructions: null, isBooker: false },
      booker: null,
    });
    expect(driverFacingPeople(people, true).passenger.phone).toBe('9876543210');
    expect(driverFacingPeople(people, true).booker).toBeNull();
  });
});
