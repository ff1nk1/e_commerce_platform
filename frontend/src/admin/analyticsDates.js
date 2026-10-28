const DAY_MS = 24 * 60 * 60 * 1000;

function formatUtcDate(date) {
  const y = date.getUTCFullYear();
  const m = String(date.getUTCMonth() + 1).padStart(2, "0");
  const d = String(date.getUTCDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

/** Inclusive last N calendar days ending today (UTC): from = today-(N-1), to = today. */
export function presetRange(days) {
  const to = new Date();
  const toUtc = new Date(
    Date.UTC(to.getUTCFullYear(), to.getUTCMonth(), to.getUTCDate()),
  );
  const fromUtc = new Date(toUtc.getTime() - (days - 1) * DAY_MS);
  return {
    from: formatUtcDate(fromUtc),
    to: formatUtcDate(toUtc),
  };
}

export const DEFAULT_PRESET_DAYS = 30;

export const PRESET_OPTIONS = [
  { id: "7", days: 7, label: "7д" },
  { id: "30", days: 30, label: "30д" },
  { id: "90", days: 90, label: "90д" },
  { id: "custom", days: null, label: "Свой" },
];

export function matchPreset(from, to) {
  if (!from || !to) return null;
  for (const opt of PRESET_OPTIONS) {
    if (opt.days == null) continue;
    const range = presetRange(opt.days);
    if (range.from === from && range.to === to) return opt.id;
  }
  return "custom";
}

export function defaultAnalyticsRange() {
  return presetRange(DEFAULT_PRESET_DAYS);
}
