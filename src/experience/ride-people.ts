import { phoneLast4 } from '../safety/safety.privacy';

export type RidePeopleInput = {
  bookedForOther?: boolean | null;
  passengerName?: string | null;
  passengerPhone?: string | null;
  instructions?: string | null;
  bookerName: string;
  bookerPhone?: string | null;
};

export function resolveRidePeople(input: RidePeopleInput) {
  const named = (input.passengerName ?? '').trim();
  const phone = (input.passengerPhone ?? '').replace(/\D/g, '');
  const forOther = Boolean(input.bookedForOther) && Boolean(named || phone);
  const passenger = {
    name: forOther ? named || 'Passenger' : input.bookerName.trim() || 'Passenger',
    phone: forOther ? phone || null : input.bookerPhone ?? null,
    instructions: (input.instructions ?? '').trim() || null,
    isBooker: !forOther,
  };
  return {
    bookedForOther: forOther,
    passenger,
    booker: {
      name: input.bookerName.trim() || 'Customer',
      phoneLast4: phoneLast4(input.bookerPhone),
    },
  };
}

export function driverFacingPeople(
  people: ReturnType<typeof resolveRidePeople>,
  assigned: boolean,
) {
  return {
    bookedForOther: people.bookedForOther,
    passenger: {
      name: people.passenger.name,
      phone: assigned ? people.passenger.phone : null,
      instructions: assigned ? people.passenger.instructions : null,
      isBooker: people.passenger.isBooker,
    },
    booker: null,
  };
}
