import { UserGender } from '@prisma/client';

const PLACEHOLDER_EMAIL = /@phone\.karnacab\.local$/i;

export function isPlaceholderEmail(email: string): boolean {
  return PLACEHOLDER_EMAIL.test(email);
}

export function isProfileComplete(user: {
  name: string;
  email: string;
  dateOfBirth: Date | null;
  gender: UserGender | null;
}): boolean {
  return (
    user.name.trim().length >= 2 &&
    user.name.trim() !== 'KarnaCab rider' &&
    !isPlaceholderEmail(user.email) &&
    Boolean(user.dateOfBirth) &&
    Boolean(user.gender)
  );
}

export function nextOnboardingStep(user: {
  name: string;
  email: string;
  dateOfBirth: Date | null;
  gender: UserGender | null;
  lastLat: unknown;
  lastLng: unknown;
}): 'PROFILE' | 'LOCATION' | 'HOME' {
  if (!isProfileComplete(user)) {
    return 'PROFILE';
  }
  if (user.lastLat == null || user.lastLng == null) {
    return 'LOCATION';
  }
  return 'HOME';
}
