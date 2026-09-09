export const SAFETY_INCIDENT_TYPES = ['sos', 'complaint', 'lost_found', 'support'] as const;
export type SafetyIncidentType = (typeof SAFETY_INCIDENT_TYPES)[number];

export const SAFETY_INCIDENT_STATUSES = ['open', 'investigating', 'resolved', 'closed'] as const;
export type SafetyIncidentStatus = (typeof SAFETY_INCIDENT_STATUSES)[number];

export const SOS_KINDS = ['police', 'emergency', 'karnacab', 'share'] as const;
export type SosKind = (typeof SOS_KINDS)[number];

export function firstName(name?: string | null): string {
  const part = (name ?? '').trim().split(/\s+/)[0];
  return part || 'User';
}

export function phoneLast4(phone?: string | null): string | null {
  const digits = (phone ?? '').replace(/\D/g, '');
  return digits.length >= 4 ? digits.slice(-4) : null;
}

export function plateHint(registration?: string | null): string | null {
  const value = (registration ?? '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
  if (!value) {
    return null;
  }
  if (value.length <= 4) {
    return value;
  }
  return `****${value.slice(-4)}`;
}

export function indianMobile(phone?: string | null): string | null {
  const digits = (phone ?? '').replace(/\D/g, '');
  const ten = digits.length > 10 ? digits.slice(-10) : digits;
  if (!/^[6-9]\d{9}$/.test(ten)) {
    return null;
  }
  return ten;
}

export function mapsUrl(lat?: number | null, lng?: number | null): string | null {
  if (lat == null || lng == null || !Number.isFinite(lat) || !Number.isFinite(lng)) {
    return null;
  }
  return `https://maps.google.com/?q=${lat},${lng}`;
}

export function canInvestigateIncidents(role: string, unrestricted: boolean): boolean {
  if (unrestricted) {
    return true;
  }
  return role === 'DISTRICT_HEAD' || role === 'STATE_HEAD' || role === 'FRANCHISE';
}
