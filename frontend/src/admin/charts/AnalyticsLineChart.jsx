import {
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

const DEFAULT_COLORS = ["#2563eb", "#94a3b8"];

/**
 * @param {{
 *   data: object[],
 *   xKey?: string,
 *   series: { dataKey: string, name?: string, stroke?: string, strokeDasharray?: string, strokeWidth?: number }[],
 *   height?: number,
 *   emptyText?: string,
 *   isLoading?: boolean,
 * }} props
 */
export default function AnalyticsLineChart({
  data,
  xKey = "date",
  series,
  height = 260,
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

  return (
    <div className="analytics-chart" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart
          data={data}
          margin={{ top: 8, right: 12, left: 0, bottom: 0 }}
        >
          <CartesianGrid strokeDasharray="3 3" stroke="#e8ecef" />
          <XAxis
            dataKey={xKey}
            tick={{ fontSize: 12, fill: "#7b8794" }}
            tickMargin={8}
          />
          <YAxis tick={{ fontSize: 12, fill: "#7b8794" }} width={48} />
          <Tooltip
            contentStyle={{
              borderRadius: 8,
              border: "1px solid #e8ecef",
              fontSize: 13,
            }}
          />
          {series.length > 1 && <Legend wrapperStyle={{ fontSize: 13 }} />}
          {series.map((s, i) => {
            const primaryStrokeWidth = 2.5;
            const secondaryStrokeWidth = 1.5;
            const strokeWidth =
              s.strokeWidth ??
              (i === 0 ? primaryStrokeWidth : secondaryStrokeWidth);
            return (
              <Line
                key={s.dataKey}
                type="monotone"
                dataKey={s.dataKey}
                name={s.name || s.dataKey}
                stroke={s.stroke || DEFAULT_COLORS[i % DEFAULT_COLORS.length]}
                strokeWidth={strokeWidth}
                strokeDasharray={s.strokeDasharray}
                dot={false}
                activeDot={{ r: 4 }}
              />
            );
          })}
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
