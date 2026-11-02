import { describe, expect, it } from "vitest";
import {
  formatCents,
  formatInteger,
  formatMoney,
  formatRate,
  fromCents,
  lineCents,
  toCents,
} from "../utils/money";

describe("toCents / fromCents", () => {
  it("converts major units to integer cents without float drift", () => {
    expect(toCents("199.90")).toBe(19990);
    expect(toCents(0.1)).toBe(10);
    expect(toCents(19.99)).toBe(1999);
    expect(toCents(null)).toBe(0);
    expect(toCents("nope")).toBe(0);
  });

  it("round-trips cents back to major units", () => {
    expect(fromCents(19990)).toBe(199.9);
    expect(fromCents(0)).toBe(0);
  });
});

describe("lineCents", () => {
  it("multiplies unit price cents by quantity", () => {
    expect(lineCents({ price: "19.99", quantity: 3 })).toBe(5997);
  });

  it("avoids the classic 0.1 + 0.2 issue", () => {
    const total =
      lineCents({ price: 0.1, quantity: 1 }) +
      lineCents({ price: 0.2, quantity: 1 });
    expect(total).toBe(30);
    expect(fromCents(total)).toBe(0.3);
  });

  it("treats missing quantities as zero", () => {
    expect(lineCents({ price: 5 })).toBe(0);
  });
});

describe("formatMoney / formatCents", () => {
  it("formats major units as RUB", () => {
    const formatted = formatMoney("199.90");
    expect(formatted).toContain("199,90");
    expect(formatted).toMatch(/₽|RUB/);
  });

  it("formats integer cents as RUB", () => {
    expect(formatCents(19990)).toContain("199,90");
  });
});

describe("formatRate / formatInteger", () => {
  it("formats ratios as percentages", () => {
    expect(formatRate(0.425)).toBe("42.5%");
    expect(formatRate(null)).toBe("—");
  });

  it("formats integers with grouping", () => {
    expect(formatInteger(1234)).toBe("1\u00a0234");
    expect(formatInteger("x")).toBe("0");
  });
});
