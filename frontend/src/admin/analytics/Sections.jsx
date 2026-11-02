import { LazyBarChart, LazyLineChart } from "./LazyCharts";

function MetricsSection({ items, data }) {
  return (
    <div className="analytics-metrics">
      {items.map((item) => (
        <div key={item.label} className="analytics-metric-card">
          <span className="analytics-metric-label">{item.label}</span>
          <strong className="analytics-metric-value">{item.value(data)}</strong>
        </div>
      ))}
    </div>
  );
}

function TableSection({ section, data, onRowAction }) {
  const rows = section.rows(data) || [];
  const isLoading = data == null;

  return (
    <>
      {section.heading ? (
        <h3 className="analytics-section-title">{section.heading}</h3>
      ) : null}
      <div className="table-wrapper">
        {isLoading ? (
          <div className="table-loading">Загрузка данных...</div>
        ) : rows.length === 0 ? (
          <div className="table-empty">{section.emptyText}</div>
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                {section.columns.map((col) => (
                  <th key={col.header}>{col.header}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, index) => (
                <tr key={row.id ?? row.date ?? index}>
                  {section.columns.map((col) => (
                    <td key={col.header}>
                      {col.action && onRowAction ? (
                        <button
                          type="button"
                          className="link-button"
                          onClick={() => onRowAction(row)}
                        >
                          {col.render(row)}
                        </button>
                      ) : (
                        col.render(row)
                      )}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  );
}

/**
 * Render a single declarative analytics section from a dimension config.
 */
export default function AnalyticsSection({ section, data, onRowAction }) {
  switch (section.type) {
    case "heading":
      return <h3 className="analytics-section-title">{section.text}</h3>;
    case "metrics":
      return <MetricsSection items={section.items} data={data} />;
    case "line":
      return (
        <LazyLineChart
          data={section.data(data)}
          isLoading={data == null}
          emptyText={section.emptyText}
          xKey={section.xKey}
          series={section.series}
        />
      );
    case "bar":
      return (
        <LazyBarChart
          data={section.data(data)}
          isLoading={data == null}
          emptyText={section.emptyText}
          layout={section.layout}
          color={section.color}
        />
      );
    case "table":
      return (
        <TableSection section={section} data={data} onRowAction={onRowAction} />
      );
    default:
      return null;
  }
}
