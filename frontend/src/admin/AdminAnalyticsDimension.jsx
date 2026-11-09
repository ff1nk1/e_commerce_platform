import { useLoaderData, useSearchParams } from "react-router-dom";
import AnalyticsDateFilter from "./AnalyticsDateFilter";
import AnalyticsSection from "./analytics/Sections";
import { buildSelectOptions, resolveView } from "./analytics/configs";
/**
 * Single config-driven renderer for every analytics dimension. The URL is the
 * one source of truth: `from`/`to`/space id come from the loader, and changing
 * any filter is a navigation (which re-runs the loader) — no local data state,
 * no refetch effects.
 */
export default function AdminAnalyticsDimension() {
  const { config, data, from, to, spaceId } = useLoaderData();

  const [searchParams, setSearchParams] = useSearchParams();

  const hasSelector = Boolean(config.selector);

  const withRange = (overrides = {}) => {
    const next = new URLSearchParams(searchParams);
    Object.entries(overrides).forEach(([key, value]) => {
      if (value === "" || value == null) next.delete(key);
      else next.set(key, String(value));
    });
    return next;
  };

  const onApply = (nextFrom, nextTo) =>
    setSearchParams(withRange({ from: nextFrom ?? from, to: nextTo ?? to }));

  const onSelect = (value) =>
    setSearchParams(withRange({ [config.selector.param]: value }));

  const view = resolveView(config, spaceId);
  const selectOptions = hasSelector
    ? buildSelectOptions(config, data, spaceId)
    : [];

  const heading = spaceId ? config.detailTitle?.(data) : null;
  const hint = spaceId ? config.detailHint?.(data) : null;

  const actionSection = config.list?.sections?.find((s) => s.rowAction);
  const onRowAction = (row) => {
    const value = actionSection?.rowAction?.getValue(row);
    if (value != null) onSelect(value);
  };

  return (
    <div>
      <div className="admin-header">
        <h2>{config.title}</h2>
      </div>

      <AnalyticsDateFilter from={from} to={to} onApply={onApply} />

      {hasSelector ? (
        <div className="analytics-filters">
          <label className="analytics-filter-field">
            {config.selector.label}
            <select
              className="search-input analytics-date-input"
              value={spaceId || ""}
              onChange={(e) => onSelect(e.target.value)}
            >
              <option value="">{config.selector.allLabel}</option>
              {selectOptions.map((opt) => (
                <option key={opt.id} value={String(opt.id)}>
                  {config.selector.optionLabel(opt)}
                </option>
              ))}
            </select>
          </label>
        </div>
      ) : null}

      {heading ? (
        <>
          <h3 className="analytics-section-title">{heading}</h3>
          {hint ? <p className="analytics-muted">{hint}</p> : null}
        </>
      ) : null}

      {(view?.sections ?? []).map((section, index) => (
        <AnalyticsSection
          key={`${section.type}-${section.text || section.heading || index}`}
          section={section}
          data={data}
          onRowAction={onRowAction}
        />
      ))}
    </div>
  );
}
