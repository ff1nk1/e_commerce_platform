import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

/**
 * @param {{
 *   data: object[],
 *   categoryKey?: string,
 *   valueKey?: string,
 *   layout?: 'vertical' | 'horizontal',
 *   height?: number,
 *   color?: string,
 *   emptyText?: string,
 *   isLoading?: boolean,
 * }} props
 */
export default function AnalyticsBarChart({
  data,
  categoryKey = "name",
  valueKey = "value",
  layout = "vertical",
  height = 260,
  color = "#2563eb",
  emptyText = "Нет данных для графика",
  isLoading = false,
}) {
  if (isLoading) {
    return (
      <div className="analytics-chart analytics-chart-empty">
        Загрузка данных...
      </div>
    );
  }
  if (!data?.length) {
    return (
      <div className="analytics-chart analytics-chart-empty">{emptyText}</div>
    );
  }

  const isHorizontal = layout === "horizontal";
  const chartHeight = isHorizontal
    ? Math.max(height, data.length * 36 + 40)
    : height;

  return (
    <div className="analytics-chart" style={{ height: chartHeight }}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart
          data={data}
          layout={isHorizontal ? "vertical" : "horizontal"}
          margin={{ top: 8, right: 12, left: isHorizontal ? 8 : 0, bottom: 0 }}
        >
          <CartesianGrid strokeDasharray="3 3" stroke="#e8ecef" />
          {isHorizontal ? (
            <>
              <XAxis type="number" tick={{ fontSize: 12, fill: "#7b8794" }} />
              <YAxis
                type="category"
                dataKey={categoryKey}
                width={120}
                tick={{ fontSize: 12, fill: "#7b8794" }}
              />
            </>
          ) : (
            <>
              <XAxis
                dataKey={categoryKey}
                tick={{ fontSize: 12, fill: "#7b8794" }}
                tickMargin={8}
              />
              <YAxis tick={{ fontSize: 12, fill: "#7b8794" }} width={48} />
            </>
          )}
          <Tooltip
            contentStyle={{
              borderRadius: 8,
              border: "1px solid #e8ecef",
              fontSize: 13,
            }}
          />
          <Bar
            dataKey={valueKey}
            fill={color}
            radius={isHorizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]}
            maxBarSize={48}
          />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
