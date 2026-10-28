import { useCallback, useEffect, useMemo, useState } from "react";
import { useLoaderData, useSearchParams } from "react-router-dom";
import { getCategoriesAnalytics } from "../api/analytics";
import AnalyticsDateFilter from "./AnalyticsDateFilter";
import { defaultAnalyticsRange } from "./analyticsDates";
import AnalyticsBarChart from "./charts/AnalyticsBarChart";

const EMPTY_ROWS = [];

function formatRate(rate) {
  if (rate == null || Number.isNaN(Number(rate))) return "—";
  return `${(Number(rate) * 100).toFixed(1)}%`;
}

function displayName(row) {
  const name = row?.name;
  if (name && !/^#\d+$/.test(name)) return name;
  if (name) return name;
  return row?.id != null ? `#${row.id}` : "—";
}

function optionLabel(opt) {
  const name = opt?.name || (opt?.id != null ? `#${opt.id}` : "—");
  if (opt?.slug) return `${name} (${opt.slug})`;
  return name;
}

export default function AdminAnalyticsCategories() {
  const initial = useLoaderData();
  const [searchParams, setSearchParams] = useSearchParams();
  const defaults = defaultAnalyticsRange();
  const [data, setData] = useState(initial);
  const [from, setFrom] = useState(searchParams.get("from") || defaults.from);
  const [to, setTo] = useState(searchParams.get("to") || defaults.to);
  const [categoryId, setCategoryId] = useState(
    searchParams.get("category_id") || "",
  );
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!searchParams.get("from") || !searchParams.get("to")) {
      const next = { from, to };
      if (categoryId) next.category_id = categoryId;
      setSearchParams(next, { replace: true });
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const fetchMetrics = useCallback(
    async (nextFrom, nextTo, nextCategoryId) => {
      setIsLoading(true);
      setError("");
      try {
        const params = {};
        if (nextFrom) params.from = nextFrom;
        if (nextTo) params.to = nextTo;
        if (nextCategoryId) params.category_id = nextCategoryId;
        const response = await getCategoriesAnalytics(params);
        setData(response.data);
        const next = {};
        if (nextFrom) next.from = nextFrom;
        if (nextTo) next.to = nextTo;
        if (nextCategoryId) next.category_id = nextCategoryId;
        setSearchParams(next, { replace: true });
      } catch (err) {
        console.error("Categories analytics error:", err);
        setError(
          err.response?.data?.error ||
            "Не удалось загрузить метрики по категориям",
        );
      } finally {
        setIsLoading(false);
      }
    },
    [setSearchParams],
  );

  const isDetail = Boolean(categoryId);
  const items = data?.items ?? EMPTY_ROWS;
  const options = data?.options ?? EMPTY_ROWS;
  const funnel = data?.funnel || {};
  const conversions = data?.conversions || {};

  const selectOptions = useMemo(() => {
    const byId = new Map();
    for (const opt of options) {
      byId.set(String(opt.id), opt);
    }
    for (const row of items) {
      if (!byId.has(String(row.id))) {
        byId.set(String(row.id), {
          id: row.id,
          name: row.name,
          slug: row.slug,
        });
      }
    }
    if (categoryId && !byId.has(categoryId) && data?.name) {
      byId.set(categoryId, {
        id: Number(categoryId),
        name: data.name,
        slug: data.slug,
      });
    }
    return Array.from(byId.values()).sort((a, b) =>
      optionLabel(a).localeCompare(optionLabel(b), "ru"),
    );
  }, [options, items, categoryId, data]);

  const rankingBars = useMemo(
    () =>
      items.map((row) => ({
        name: displayName(row),
        value: Number(row.orders_count) || 0,
      })),
    [items],
  );

  const onCategoryChange = (e) => {
    const nextId = e.target.value;
    setCategoryId(nextId);
    fetchMetrics(from, to, nextId);
  };

  const title = isDetail ? data?.name || displayName({ id: categoryId }) : null;
  const showIdHint = isDetail && data?.name && !/^#\d+$/.test(data.name);

  return (
    <div>
      <div className="admin-header">
        <h2>Аналитика: категории</h2>
      </div>

      <AnalyticsDateFilter
        from={from}
        to={to}
        onFromChange={setFrom}
        onToChange={setTo}
        onApply={(nextFrom, nextTo) =>
          fetchMetrics(nextFrom ?? from, nextTo ?? to, categoryId)
        }
        isLoading={isLoading}
      />

      <div className="analytics-filters">
        <label className="analytics-filter-field">
          Категория
          <select
            className="search-input analytics-date-input"
            value={categoryId}
            onChange={onCategoryChange}
            disabled={isLoading}
          >
            <option value="">Все категории</option>
            {selectOptions.map((opt) => (
              <option key={opt.id} value={String(opt.id)}>
                {optionLabel(opt)}
              </option>
            ))}
          </select>
        </label>
      </div>

      {error && <div className="analytics-error">{error}</div>}

      {isDetail ? (
        <>
          <h3 className="analytics-section-title">{title}</h3>
          {(showIdHint || data?.slug) && (
            <p className="analytics-muted">
              {[
                showIdHint ? `id: ${categoryId}` : null,
                data?.slug ? `slug: ${data.slug}` : null,
              ]
                .filter(Boolean)
                .join(" · ")}
            </p>
          )}
          <div className="analytics-metrics">
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Заказов</span>
              <strong className="analytics-metric-value">
                {data?.orders_count ?? 0}
              </strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Товаров (шт.)</span>
              <strong className="analytics-metric-value">
                {data?.items_quantity ?? 0}
              </strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Сумма позиций</span>
              <strong className="analytics-metric-value">
                {data?.line_amount ?? "0.00"}
              </strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Оплачено (позиции)</span>
              <strong className="analytics-metric-value">
                {data?.paid_line_amount ?? "0.00"}
              </strong>
            </div>
          </div>

          <h3 className="analytics-section-title">Воронка заказов категории</h3>
          <div className="analytics-metrics">
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Created</span>
              <strong className="analytics-metric-value">
                {funnel.created ?? 0}
              </strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Paid</span>
              <strong className="analytics-metric-value">
                {funnel.paid ?? 0}
              </strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Cancelled</span>
              <strong className="analytics-metric-value">
                {funnel.cancelled ?? 0}
              </strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Open</span>
              <strong className="analytics-metric-value">
                {funnel.open ?? 0}
              </strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Completed</span>
              <strong className="analytics-metric-value">
                {funnel.completed ?? 0}
              </strong>
            </div>
          </div>

          <h3 className="analytics-section-title">Конверсия</h3>
          <div className="analytics-metrics">
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Created → Paid</span>
              <strong className="analytics-metric-value">
                {formatRate(conversions.created_to_paid)}
              </strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">
                Created → Cancelled
              </span>
              <strong className="analytics-metric-value">
                {formatRate(conversions.created_to_cancelled)}
              </strong>
            </div>
          </div>
        </>
      ) : (
        <>
          <h3 className="analytics-section-title">Топ категорий</h3>
          <AnalyticsBarChart
            data={rankingBars}
            isLoading={isLoading}
            emptyText="Нет данных по категориям"
            color="#b45309"
          />

          <div className="table-wrapper">
            {isLoading ? (
              <div className="table-loading">Загрузка данных...</div>
            ) : items.length === 0 ? (
              <div className="table-empty">Нет данных по категориям</div>
            ) : (
              <table className="admin-table">
                <thead>
                  <tr>
                    <th>Категория</th>
                    <th>Slug</th>
                    <th>Заказов</th>
                    <th>Товаров (шт.)</th>
                    <th>Сумма позиций</th>
                    <th>Оплачено</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((row) => (
                    <tr key={row.id}>
                      <td>
                        <button
                          type="button"
                          className="link-button"
                          onClick={() => {
                            setCategoryId(String(row.id));
                            fetchMetrics(from, to, String(row.id));
                          }}
                        >
                          {displayName(row)}
                        </button>
                        {row.name && !/^#\d+$/.test(row.name) ? (
                          <span className="analytics-id-hint"> #{row.id}</span>
                        ) : null}
                      </td>
                      <td>{row.slug || "—"}</td>
                      <td>{row.orders_count}</td>
                      <td>{row.items_quantity}</td>
                      <td>{row.line_amount}</td>
                      <td>{row.paid_line_amount}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </>
      )}
    </div>
  );
}
