import {
  getCategories,
  getCategoryProducts,
  getProduct,
  getStoreProducts,
  getStores,
} from "../api/catalog";

/** Shared query params for catalog grids. */
function gridParams(request) {
  const url = new URL(request.url);
  const params = Object.fromEntries(url.searchParams.entries());
  if (params.status === "all") delete params.status;
  return params;
}

export async function categoriesLoader() {
  try {
    const { data } = await getCategories();
    return { categories: Array.isArray(data) ? data : (data?.data ?? []) };
  } catch {
    return { categories: [], error: "Не удалось загрузить категории." };
  }
}

export async function storesLoader() {
  try {
    const { data } = await getStores();
    return { stores: Array.isArray(data) ? data : (data?.data ?? []) };
  } catch {
    return { stores: [], error: "Не удалось загрузить магазины." };
  }
}

export async function categoryProductsLoader({ params, request }) {
  try {
    const { data } = await getCategoryProducts(
      params.slug,
      gridParams(request),
    );
    return {
      category: data.category ?? null,
      products: data.products?.data ?? [],
      pagination: {
        current_page: data.products?.current_page ?? 1,
        last_page: data.products?.last_page ?? 1,
      },
      error: null,
    };
  } catch {
    return {
      category: null,
      products: [],
      pagination: { current_page: 1, last_page: 1 },
      error: "Не удалось загрузить товары.",
    };
  }
}

export async function storeProductsLoader({ params, request }) {
  try {
    const { data } = await getStoreProducts(
      params.storeId,
      gridParams(request),
    );
    return {
      store: data.store ?? null,
      products: data.products?.data ?? [],
      pagination: {
        current_page: data.products?.current_page ?? 1,
        last_page: data.products?.last_page ?? 1,
      },
      error: null,
    };
  } catch {
    return {
      store: null,
      products: [],
      pagination: { current_page: 1, last_page: 1 },
      error: "Не удалось загрузить товары магазина.",
    };
  }
}

export async function productLoader({ params }) {
  try {
    const { data } = await getProduct(params.id);
    return { product: data.product, error: null };
  } catch {
    return {
      product: null,
      error: "Не удалось загрузить информацию о товаре.",
    };
  }
}

export async function assistantLoader() {
  try {
    const { data } = await getStores();
    return { stores: Array.isArray(data) ? data : (data?.data ?? []) };
  } catch {
    return { stores: [] };
  }
}
