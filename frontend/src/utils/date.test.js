import { describe, expect, it } from "vitest";
import { formatDate, formatDateTime, formatShortDateTime } from "../utils/date";

describe("formatDateTime", () => {
  it("formats ISO strings", () => {
    const value = formatDateTime("2026-11-02T10:30:00Z");
    expect(value).toMatch(/\d{2}\.\d{2}\.\d{4}/);
    expect(value).toMatch(/\d{2}:\d{2}/);
  });

  it("handles unix seconds and milliseconds", () => {
    expect(formatDateTime(1_700_000_000)).toMatch(/\d{2}\.\d{2}\.\d{4}/);
    expect(formatDateTime(1_700_000_000_000)).toMatch(/\d{2}\.\d{2}\.\d{4}/);
  });

  it("falls back for invalid input", () => {
    expect(formatDateTime(null)).toBe("—");
    expect(formatDateTime("not-a-date")).toBe("—");
    expect(formatDateTime(null, "")).toBe("");
  });
});

describe("formatShortDateTime / formatDate", () => {
  it("produces a compact form", () => {
    expect(formatShortDateTime("2026-11-02T10:30:00Z")).toMatch(
      /\d{2}\.\d{2}, \d{2}:\d{2}/,
    );
  });

  it("formats dates only", () => {
    expect(formatDate("2026-11-02T10:30:00Z")).toMatch(/^\d{2}\.\d{2}\.\d{4}$/);
    expect(formatDate(undefined)).toBe("—");
  });
});
