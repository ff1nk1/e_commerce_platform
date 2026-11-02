import { describe, expect, it } from "vitest";
import {
  buildRequestParams,
  EMPTY_FACETS,
  hasActiveFilters,
  parseAttrParams,
  readSelection,
} from "./searchParams";

describe("parseAttrParams", () => {
  it("collects repeated attr[Name] keys", () => {
    const params = new URLSearchParams(
      "attr[Цвет]=красный&attr[Цвет]=синий&attr[Размер]=M",
    );
    expect(parseAttrParams(params)).toEqual({
      Цвет: ["красный", "синий"],
      Размер: ["M"],
    });
  });

  it("ignores non-attr keys and duplicates", () => {
    const params = new URLSearchParams(
      "q=phone&attr[Цвет]=красный&attr[Цвет]=красный",
    );
    expect(parseAttrParams(params)).toEqual({ Цвет: ["красный"] });
  });
});

describe("buildRequestParams", () => {
  it("maps query + single category to scalars", () => {
    const params = new URLSearchParams("q=phone&category_id=3&page=2");
    expect(buildRequestParams(params)).toEqual({
      q: "phone",
      category_id: "3",
      page: "2",
    });
  });

  it("maps multiple categories to an array", () => {
    const params = new URLSearchParams("category_id=3&category_id=5");
    expect(buildRequestParams(params)).toEqual({
      category_id: ["3", "5"],
    });
  });

  it("nests attributes under attr", () => {
    const params = new URLSearchParams("attr[Цвет]=красный");
    expect(buildRequestParams(params)).toEqual({
      attr: { Цвет: ["красный"] },
    });
  });

  it("omits empty values", () => {
    const params = new URLSearchParams("q=&sort=&min_price=10");
    expect(buildRequestParams(params)).toEqual({ min_price: "10" });
  });
});

describe("readSelection", () => {
  it("reads current facet selection from the URL", () => {
    const params = new URLSearchParams(
      "category_id=3&in_stock=1&price_range=1000-5000&sort=price_asc&attr[Цвет]=красный",
    );
    const selection = readSelection(params);
    expect(selection.categoryIds).toEqual(["3"]);
    expect(selection.inStock).toBe("1");
    expect(selection.priceRange).toBe("1000-5000");
    expect(selection.sort).toBe("price_asc");
    expect(selection.attrs).toEqual({ Цвет: ["красный"] });
  });
});

describe("hasActiveFilters", () => {
  it("ignores the free-text query", () => {
    expect(hasActiveFilters(new URLSearchParams("q=phone"))).toBe(false);
    expect(hasActiveFilters(new URLSearchParams("q=phone&sort=new"))).toBe(
      true,
    );
    expect(hasActiveFilters(new URLSearchParams(""))).toBe(false);
  });
});

describe("EMPTY_FACETS", () => {
  it("exposes the expected shape", () => {
    expect(EMPTY_FACETS).toEqual({
      categories: [],
      price_ranges: [],
      availability: [],
      attributes: {},
    });
  });
});
