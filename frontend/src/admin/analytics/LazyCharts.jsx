import { lazy, Suspense } from "react";

/**
 * recharts is the heaviest dependency in the app and is only used by the admin
 * analytics pages. Loading the chart components lazily here — combined with the
 * lazy analytics route — keeps recharts out of the shopper's initial bundle.
 */
const AnalyticsBarChart = lazy(() => import("../charts/AnalyticsBarChart"));
const AnalyticsLineChart = lazy(() => import("../charts/AnalyticsLineChart"));

function ChartFallback() {
  return (
    <div className="analytics-chart analytics-chart-empty">
      Загрузка данных...
    </div>
  );
}

export function LazyBarChart(props) {
  return (
    <Suspense fallback={<ChartFallback />}>
      <AnalyticsBarChart {...props} />
    </Suspense>
  );
}

export function LazyLineChart(props) {
  return (
    <Suspense fallback={<ChartFallback />}>
      <AnalyticsLineChart {...props} />
    </Suspense>
  );
}
