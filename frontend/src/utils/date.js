/**
 * Normalize a date-ish value (ISO string, Date, or unix seconds/ms) to a Date.
 * Returns null when the value cannot be parsed.
 */
function toDate(value) {
  if (value == null || value === "") return null;
  if (value instanceof Date)
    return Number.isNaN(value.getTime()) ? null : value;
  if (typeof value === "number") {
    // Heuristic: values below 1e12 are unix seconds, not milliseconds.
    const ms = value < 1e12 ? value * 1000 : value;
    const date = new Date(ms);
    return Number.isNaN(date.getTime()) ? null : date;
  }
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

const dateTimeFormatter = new Intl.DateTimeFormat("ru-RU", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
});

const shortDateTimeFormatter = new Intl.DateTimeFormat("ru-RU", {
  day: "2-digit",
  month: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
});

const dateFormatter = new Intl.DateTimeFormat("ru-RU", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
});

/** "31.12.2026, 14:05" — falls back to "—" when missing/invalid. */
export function formatDateTime(value, fallback = "—") {
  const date = toDate(value);
  return date ? dateTimeFormatter.format(date) : fallback;
}

/** "31.12, 14:05" — compact date+time. */
export function formatShortDateTime(value, fallback = "—") {
  const date = toDate(value);
  return date ? shortDateTimeFormatter.format(date) : fallback;
}

/** "31.12.2026" */
export function formatDate(value, fallback = "—") {
  const date = toDate(value);
  return date ? dateFormatter.format(date) : fallback;
}
