import {
  getCategoriesAnalytics,
  getOrdersAnalytics,
  getSearchAnalytics,
  getStoresAnalytics,
  getUsersAnalytics,
} from "../../api/analytics";
import { formatRate } from "../../utils/money";

const EMPTY_ROWS = [];

function isPlaceholderName(name) {
  return typeof name === "string" && /^#\d+$/.test(name);
}

function displayName(row) {
  const name = row?.name;
  if (name && !isPlaceholderName(name)) return name;
  if (name) return name;
  return row?.id != null ? `#${row.id}` : "—";
}

function optionLabel(opt) {
  const name = opt?.name || (opt?.id != null ? `#${opt.id}` : "—");
  if (opt?.slug) return `${name} (${opt.slug})`;
  return name;
}

const QUERY_COLUMNS = [
  { header: "Запрос", render: (row) => row.query || "(empty)" },
  { header: "Кол-во", render: (row) => row.count },
];

const FUNNEL_METRICS = [
  { label: "Created", value: (d) => d?.funnel?.created ?? 0 },
  { label: "Paid", value: (d) => d?.funnel?.paid ?? 0 },
  { label: "Cancelled", value: (d) => d?.funnel?.cancelled ?? 0 },
  { label: "Open", value: (d) => d?.funnel?.open ?? 0 },
  { label: "Completed (shipped)", value: (d) => d?.funnel?.completed ?? 0 },
];

const CONVERSION_METRICS = [
  {
    label: "Created → Paid",
    value: (d) => formatRate(d?.conversions?.created_to_paid),
  },
  {
    label: "Created → Cancelled",
    value: (d) => formatRate(d?.conversions?.created_to_cancelled),
  },
];

const funnelBars = (d) => [
  { name: "Created", value: d?.funnel?.created ?? 0 },
  { name: "Paid", value: d?.funnel?.paid ?? 0 },
  { name: "Cancelled", value: d?.funnel?.cancelled ?? 0 },
  { name: "Open", value: d?.funnel?.open ?? 0 },
  { name: "Completed", value: d?.funnel?.completed ?? 0 },
];

/**
 * Declarative description of every admin analytics dimension. One renderer
 * (AdminAnalyticsDimension) turns these into pages, so the five former
 * near-duplicate components collapse into a single code path.
 *
 * Section types:
 *  - metrics: { items: [{ label, value(data) }] }
 *  - heading: { text }
 *  - line:    { data(data), series, xKey?, emptyText? }
 *  - bar:     { data(data), layout?, color?, emptyText? }
 *  - table:   { columns: [{ header, render(row) }], rows(data), emptyText?,
 *               heading?, rowAction?: { param, getValue(row) } }
 */
export const ANALYTICS_DIMENSIONS = {
  users: {
    title: "Рост числа пользователей",
    load: async (params) => (await getUsersAnalytics(params)).data,
    view: {
      sections: [
        {
          type: "metrics",
          items: [
            {
              label: "Всего пользователей",
              value: (d) => d?.total_users ?? 0,
            },
          ],
        },
        { type: "heading", text: "Регистрации по дням" },
        {
          type: "line",
          data: (d) => d?.growth || EMPTY_ROWS,
          emptyText: "Нет данных о регистрациях",
          series: [
            { dataKey: "count", name: "Новых", stroke: "#2563eb" },
            {
              dataKey: "cumulative",
              name: "Накопительно",
              stroke: "#94a3b8",
              strokeDasharray: "4 4",
              strokeWidth: 1.5,
            },
          ],
        },
        {
          type: "table",
          rows: (d) => d?.growth || EMPTY_ROWS,
          emptyText: "Нет данных о регистрациях",
          columns: [
            { header: "Дата", render: (row) => row.date },
            { header: "Новых", render: (row) => row.count },
            { header: "Накопительно", render: (row) => row.cumulative },
          ],
        },
      ],
    },
  },

  orders: {
    title: "Аналитика: заказы",
    load: async (params) => (await getOrdersAnalytics(params)).data,
    view: {
      sections: [
        { type: "heading", text: "Воронка" },
        { type: "metrics", items: FUNNEL_METRICS },
        {
          type: "bar",
          data: funnelBars,
          color: "#2563eb",
          emptyText: "Нет данных воронки",
        },
        { type: "heading", text: "Конверсия" },
        { type: "metrics", items: CONVERSION_METRICS },
        { type: "heading", text: "Выручка" },
        {
          type: "metrics",
          items: [
            {
              label: "Выручка (paid)",
              value: (d) => d?.revenue?.total ?? "0.00",
            },
            {
              label: "Средний чек",
              value: (d) => d?.avg_order_value ?? "0.00",
            },
          ],
        },
        { type: "heading", text: "Выручка по дням" },
        {
          type: "line",
          data: (d) =>
            (d?.revenue?.by_day || []).map((row) => ({
              date: row.date,
              amount: Number(row.amount) || 0,
            })),
          emptyText: "Нет данных о выручке",
          series: [{ dataKey: "amount", name: "Выручка", stroke: "#059669" }],
        },
        {
          type: "table",
          rows: (d) => d?.revenue?.by_day || EMPTY_ROWS,
          emptyText: "Нет данных о выручке",
          columns: [
            { header: "Дата", render: (row) => row.date },
            { header: "Сумма", render: (row) => row.amount },
          ],
        },
      ],
    },
  },

  search: {
    title: "Поисковые запросы",
    limit: 20,
    load: async (params) => (await getSearchAnalytics(params)).data,
    view: {
      sections: [
        {
          type: "metrics",
          items: [
            { label: "Всего поисков", value: (d) => d?.total_searches ?? 0 },
            {
              label: "Без результатов",
              value: (d) => d?.zero_result_count ?? 0,
            },
            {
              label: "Доля пустых",
              value: (d) => formatRate(d?.zero_result_rate),
            },
          ],
        },
        { type: "heading", text: "Топ запросов" },
        {
          type: "bar",
          layout: "horizontal",
          color: "#7c3aed",
          emptyText: "Нет популярных запросов",
          data: (d) =>
            (d?.popular_queries || []).slice(0, 10).map((row) => ({
              name: row.query || "(empty)",
              value: Number(row.count) || 0,
            })),
        },
        {
          type: "table",
          heading: "Популярные запросы",
          rows: (d) => d?.popular_queries || EMPTY_ROWS,
          emptyText: "Нет популярных запросов",
          columns: QUERY_COLUMNS,
        },
        {
          type: "table",
          heading: "Запросы без результатов",
          rows: (d) => d?.zero_result_queries || EMPTY_ROWS,
          emptyText: "Нет запросов без результатов",
          columns: QUERY_COLUMNS,
        },
      ],
    },
  },

  stores: {
    title: "Аналитика: магазины",
    limit: 20,
    selector: {
      param: "store_id",
      label: "Магазин",
      allLabel: "Все магазины",
      optionLabel,
    },
    detailTitle: (d) => d?.name || null,
    load: async (params) => (await getStoresAnalytics(params)).data,
    list: {
      sections: [
        { type: "heading", text: "Топ магазинов" },
        {
          type: "bar",
          color: "#0f766e",
          emptyText: "Нет данных по магазинам",
          data: (d) =>
            (d?.items || []).map((row) => ({
              name: displayName(row),
              value: Number(row.orders_count) || 0,
            })),
        },
        {
          type: "table",
          rows: (d) => d?.items || EMPTY_ROWS,
          emptyText: "Нет данных по магазинам",
          rowAction: { param: "store_id", getValue: (row) => row.id },
          columns: [
            {
              header: "Магазин",
              render: (row) => displayName(row),
              action: true,
            },
            { header: "Заказов", render: (row) => row.orders_count },
            { header: "Товаров (шт.)", render: (row) => row.items_quantity },
            { header: "Сумма позиций", render: (row) => row.line_amount },
            { header: "Оплачено", render: (row) => row.paid_line_amount },
          ],
        },
      ],
    },
    detail: {
      sections: [
        {
          type: "metrics",
          items: [
            { label: "Заказов", value: (d) => d?.orders_count ?? 0 },
            {
              label: "Товаров (шт.)",
              value: (d) => d?.items_quantity ?? 0,
            },
            { label: "Сумма позиций", value: (d) => d?.line_amount ?? "0.00" },
            {
              label: "Оплачено (позиции)",
              value: (d) => d?.paid_line_amount ?? "0.00",
            },
          ],
        },
        { type: "heading", text: "Воронка заказов магазина" },
        { type: "metrics", items: FUNNEL_METRICS },
        { type: "heading", text: "Конверсия" },
        { type: "metrics", items: CONVERSION_METRICS },
      ],
    },
  },

  categories: {
    title: "Аналитика: категории",
    limit: 20,
    selector: {
      param: "category_id",
      label: "Категория",
      allLabel: "Все категории",
      optionLabel,
    },
    detailTitle: (d) => d?.name || null,
    detailHint: (d) => (d?.slug ? `slug: ${d.slug}` : null),
    load: async (params) => (await getCategoriesAnalytics(params)).data,
    list: {
      sections: [
        { type: "heading", text: "Топ категорий" },
        {
          type: "bar",
          color: "#b45309",
          emptyText: "Нет данных по категориям",
          data: (d) =>
            (d?.items || []).map((row) => ({
              name: displayName(row),
              value: Number(row.orders_count) || 0,
            })),
        },
        {
          type: "table",
          rows: (d) => d?.items || EMPTY_ROWS,
          emptyText: "Нет данных по категориям",
          rowAction: { param: "category_id", getValue: (row) => row.id },
          columns: [
            {
              header: "Категория",
              render: (row) => displayName(row),
              action: true,
            },
            { header: "Slug", render: (row) => row.slug || "—" },
            { header: "Заказов", render: (row) => row.orders_count },
            { header: "Товаров (шт.)", render: (row) => row.items_quantity },
            { header: "Сумма позиций", render: (row) => row.line_amount },
            { header: "Оплачено", render: (row) => row.paid_line_amount },
          ],
        },
      ],
    },
    detail: {
      sections: [
        {
          type: "metrics",
          items: [
            { label: "Заказов", value: (d) => d?.orders_count ?? 0 },
            {
              label: "Товаров (шт.)",
              value: (d) => d?.items_quantity ?? 0,
            },
            { label: "Сумма позиций", value: (d) => d?.line_amount ?? "0.00" },
            {
              label: "Оплачено (позиции)",
              value: (d) => d?.paid_line_amount ?? "0.00",
            },
          ],
        },
        { type: "heading", text: "Воронка заказов категории" },
        { type: "metrics", items: FUNNEL_METRICS },
        { type: "heading", text: "Конверсия" },
        { type: "metrics", items: CONVERSION_METRICS },
      ],
    },
  },
};

export const ANALYTICS_DIMENSION_IDS = Object.keys(ANALYTICS_DIMENSIONS);

/**
 * Merge selector options (from API) with rows (from the list payload) so the
 * dropdown always has an entry for the currently selected dimension.
 */
export function buildSelectOptions(config, data, selectedId) {
  const byId = new Map();
  for (const opt of data?.options || []) {
    byId.set(String(opt.id), opt);
  }
  for (const row of data?.items || []) {
    if (!byId.has(String(row.id))) {
      byId.set(String(row.id), { id: row.id, name: row.name, slug: row.slug });
    }
  }
  if (selectedId && !byId.has(String(selectedId)) && data?.name) {
    byId.set(String(selectedId), {
      id: Number(selectedId),
      name: data.name,
      slug: data.slug,
    });
  }
  const label = config.selector?.optionLabel ?? ((o) => o?.name || o?.id);
  return Array.from(byId.values()).sort((a, b) =>
    String(label(a)).localeCompare(String(label(b)), "ru"),
  );
}

export { displayName, optionLabel };
