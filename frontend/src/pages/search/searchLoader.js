import { searchProducts } from "../../api/catalog";
import { buildRequestParams, EMPTY_FACETS } from "./searchParams";

/**
 * Route loader for /search. The URL query string is the single source of truth:
 * it is read here, translated to API params, and returned alongside the result.
 */
export async function searchLoader({ request }) {
  const url = new URL(request.url);
  try {
    const { data } = await searchProducts(buildRequestParams(url.searchParams));
    return {
      products: data.products?.data ?? [],
      facets: data.facets ?? EMPTY_FACETS,
      pagination: {
        current_page: data.products?.current_page ?? 1,
        last_page: data.products?.last_page ?? 1,
        total: data.products?.total ?? 0,
      },
      error: null,
    };
  } catch {
    return {
      products: [],
      facets: EMPTY_FACETS,
      pagination: { current_page: 1, last_page: 1, total: 0 },
      error: "Не удалось выполнить поиск.",
    };
  }
}
