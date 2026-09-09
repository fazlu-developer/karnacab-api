export type TravelCategory = { key: string; label: string };

export const DEFAULT_TRAVEL_CATEGORIES: TravelCategory[] = [
  { key: 'BIHAR_TOURS', label: 'Bihar Tours' },
  { key: 'SIGHTSEEING', label: 'Sightseeing' },
  { key: 'DARSHAN_YATRA', label: 'Darshan/Yatra' },
  { key: 'FAMILY', label: 'Family' },
  { key: 'GROUP_TOURS', label: 'Group Tours' },
  { key: 'CUSTOM_TOURS', label: 'Custom Tours' },
  { key: 'HOTEL_CAB', label: 'Hotel + Cab' },
  { key: 'AIRPORT_CAB', label: 'Airport + Cab' },
  { key: 'RAILWAY_CAB', label: 'Railway + Cab' },
];

export function parseCategoryList(raw?: string | null): TravelCategory[] {
  if (!raw) {
    return DEFAULT_TRAVEL_CATEGORIES;
  }
  try {
    const parsed = JSON.parse(raw) as TravelCategory[];
    return Array.isArray(parsed) && parsed.length ? parsed : DEFAULT_TRAVEL_CATEGORIES;
  } catch {
    return DEFAULT_TRAVEL_CATEGORIES;
  }
}

export function categoryLabel(key: string, categories = DEFAULT_TRAVEL_CATEGORIES) {
  return categories.find((row) => row.key === key)?.label ?? key;
}

export function asStringList(value: unknown): string[] {
  if (!Array.isArray(value)) {
    return [];
  }
  return value.map((item) => String(item)).filter(Boolean);
}

export function asItinerary(value: unknown): { day: number; title: string; detail: string }[] {
  if (!Array.isArray(value)) {
    return [];
  }
  return value.map((item, index) => {
    const row = item && typeof item === 'object' ? (item as Record<string, unknown>) : {};
    return {
      day: typeof row.day === 'number' ? row.day : index + 1,
      title: String(row.title ?? ''),
      detail: String(row.detail ?? ''),
    };
  });
}

export function dateAllowed(available: unknown, date: string) {
  const dates = asStringList(available);
  if (!dates.length) {
    return true;
  }
  return dates.includes(date);
}
