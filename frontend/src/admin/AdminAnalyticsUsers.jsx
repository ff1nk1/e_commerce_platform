import { useCallback, useEffect, useState } from "react";
import { useLoaderData, useSearchParams } from "react-router-dom";
import { getUsersAnalytics } from "../api/analytics";
import AnalyticsDateFilter from "./AnalyticsDateFilter";
import { defaultAnalyticsRange } from "./analyticsDates";
import AnalyticsLineChart from "./charts/AnalyticsLineChart";

export default function AdminAnalyticsUsers() {
  const initial = useLoaderData();
  const [searchParams, setSearchParams] = useSearchParams();
  const defaults = defaultAnalyticsRange();
  const [data, setData] = useState(initial);
  const [from, setFrom] = useState(searchParams.get("from") || defaults.from);
  const [to, setTo] = useState(searchParams.get("to") || defaults.to);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!searchParams.get("from") || !searchParams.get("to")) {
      setSearchParams({ from, to }, { replace: true });
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const fetchMetrics = useCallback(
    async (nextFrom, nextTo) => {
      setIsLoading(true);
      setError("");
      try {
        const params = {};
        if (nextFrom) params.from = nextFrom;
        if (nextTo) params.to = nextTo;
        const response = await getUsersAnalytics(params);
        setData(response.data);
        const next = {};
        if (nextFrom) next.from = nextFrom;
        if (nextTo) next.to = nextTo;
        setSearchParams(next, { replace: true });
      } catch (err) {
        console.error("Users analytics error:", err);
        setError(
          err.response?.data?.error ||
            "Не удалось загрузить метрики пользователей",
        );
      } finally {
        setIsLoading(false);
      }
    },
    [setSearchParams],
  );

  const growth = data?.growth || [];

  return (
    <div>
      <div className="admin-header">
        <h2>Рост числа пользователей</h2>
      </div>

      <AnalyticsDateFilter
        from={from}
        to={to}
        onFromChange={setFrom}
        onToChange={setTo}
        onApply={(nextFrom, nextTo) =>
          fetchMetrics(nextFrom ?? from, nextTo ?? to)
        }
        isLoading={isLoading}
      />

      {error && <div className="analytics-error">{error}</div>}

      <div className="analytics-metrics">
        <div className="analytics-metric-card">
          <span className="analytics-metric-label">Всего пользователей</span>
          <strong className="analytics-metric-value">
            {data?.total_users ?? 0}
          </strong>
        </div>
      </div>

      <h3 className="analytics-section-title">Регистрации по дням</h3>
      <AnalyticsLineChart
        data={growth}
        isLoading={isLoading}
        emptyText="Нет данных о регистрациях"
        series={[
          { dataKey: "count", name: "Новых", stroke: "#2563eb" },
          {
            dataKey: "cumulative",
            name: "Накопительно",
            stroke: "#94a3b8",
            strokeDasharray: "4 4",
            strokeWidth: 1.5,
          },
        ]}
      />

      <div className="table-wrapper">
        {isLoading ? (
          <div className="table-loading">Загрузка данных...</div>
        ) : growth.length === 0 ? (
          <div className="table-empty">Нет данных о регистрациях</div>
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th>Дата</th>
                <th>Новых</th>
                <th>Накопительно</th>
              </tr>
            </thead>
            <tbody>
              {growth.map((row) => (
                <tr key={row.date}>
                  <td>{row.date}</td>
                  <td>{row.count}</td>
                  <td>{row.cumulative}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
