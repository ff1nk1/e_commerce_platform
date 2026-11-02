const rub = new Intl.NumberFormat("ru-RU", {
  style: "currency",
  currency: "RUB",
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

/**
 * Convert a major-unit amount (e.g. "199.90") to integer cents.
 * All cart math is done in cents to avoid floating point drift.
 */
export function toCents(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100);
}

/** Convert integer cents back to a major-unit number. */
export function fromCents(cents) {
  const n = Number(cents);
  if (!Number.isFinite(n)) return 0;
  return n / 100;
}

/** Format a major-unit amount as a RUB currency string. */
export function formatMoney(value) {
  return rub.format(fromCents(toCents(value)));
}

/** Format integer cents as a RUB currency string. */
export function formatCents(cents) {
  const n = Number(cents);
  return rub.format(Number.isFinite(n) ? n / 100 : 0);
}

/** Line total in integer cents (unit price cents × quantity). */
export function lineCents(item) {
  return toCents(item.price) * Number(item.quantity || 0);
}

/** Format a ratio (0..1) as a percentage, e.g. 0.42 -> "42.0%". */
export function formatRate(rate) {
  if (rate == null || Number.isNaN(Number(rate))) return "—";
  return `${(Number(rate) * 100).toFixed(1)}%`;
}

/** Format an integer with ru-RU thousand separators. */
export function formatInteger(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return "0";
  return new Intl.NumberFormat("ru-RU").format(n);
}
