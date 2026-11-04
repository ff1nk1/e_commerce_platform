import { describe, expect, it } from "vitest";
import {
  ANALYTICS_DIMENSIONS,
  ANALYTICS_DIMENSION_IDS,
  buildSelectOptions,
} from "./configs";

describe("ANALYTICS_DIMENSIONS", () => {
  it("describes the five expected dimensions", () => {
    expect(ANALYTICS_DIMENSION_IDS.toSorted()).toEqual(
      ["categories", "orders", "search", "stores", "users"].toSorted(),
    );
  });

  it("exposes a loader and a view for every dimension", () => {
    for (const id of ANALYTICS_DIMENSION_IDS) {
      const config = ANALYTICS_DIMENSIONS[id];
      expect(typeof config.load).toBe("function");
      expect(config.title).toBeTruthy();
      const hasSections =
        (config.list?.sections?.length ?? 0) > 0 ||
        (config.view?.sections?.length ?? 0) > 0;
      expect(hasSections).toBe(true);
    }
  });

  it("only stores/categories are selectable dimensions", () => {
    expect(ANALYTICS_DIMENSIONS.stores.selector.param).toBe("store_id");
    expect(ANALYTICS_DIMENSIONS.categories.selector.param).toBe("category_id");
    expect(ANALYTICS_DIMENSIONS.users.selector).toBeUndefined();
  });
});

describe("buildSelectOptions", () => {
  const config = ANALYTICS_DIMENSIONS.stores;

  it("merges API options with list rows", () => {
    const data = {
      options: [{ id: 1, name: "Alpha" }],
      items: [{ id: 2, name: "Beta", orders_count: 5 }],
    };
    const options = buildSelectOptions(config, data, null);
    expect(options.map((o) => o.name).toSorted()).toEqual(["Alpha", "Beta"]);
  });

  it("sorts options by label", () => {
    const data = {
      options: [
        { id: 2, name: "Beta" },
        { id: 1, name: "Alpha" },
      ],
    };
    expect(buildSelectOptions(config, data, null).map((o) => o.name)).toEqual([
      "Alpha",
      "Beta",
    ]);
  });

  it("includes the selected id even without a matching option", () => {
    const data = { name: "Gamma", options: [], items: [] };
    const options = buildSelectOptions(config, data, "7");
    expect(options).toHaveLength(1);
    expect(String(options[0].id)).toBe("7");
    expect(options[0].name).toBe("Gamma");
  });
});
