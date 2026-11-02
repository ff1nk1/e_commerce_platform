import { useMemo, useState } from "react";
import { matchPreset, PRESET_OPTIONS, presetRange } from "./analyticsDates";

export default function AnalyticsDateFilter({ from, to, onApply, isLoading }) {
  const [forceCustom, setForceCustom] = useState(false);
  const matched = useMemo(() => matchPreset(from, to), [from, to]);
  const activePreset = forceCustom || !matched ? "custom" : matched;
  const isCustom = activePreset === "custom";
  const [customFrom, setCustomFrom] = useState(from || "");
  const [customTo, setCustomTo] = useState(to || "");
  const [syncedRange, setSyncedRange] = useState(`${from}|${to}`);
  if (syncedRange !== `${from}|${to}`) {
    setSyncedRange(`${from}|${to}`);
    setCustomFrom(from || "");
    setCustomTo(to || "");
  }

  const applyPreset = (days) => {
    const range = presetRange(days);
    setForceCustom(false);
    onApply(range.from, range.to);
  };

  return (
    <div className="analytics-filters">
      <fieldset className="analytics-presets">
        <legend className="analytics-presets-label">Период</legend>
        {PRESET_OPTIONS.map((opt) => {
          if (opt.days != null) {
            return (
              <button
                key={opt.id}
                type="button"
                className={`analytics-preset-btn${activePreset === opt.id ? " is-active" : ""}`}
                disabled={isLoading}
                onClick={() => applyPreset(opt.days)}
              >
                {opt.label}
              </button>
            );
          }
          return (
            <button
              key={opt.id}
              type="button"
              className={`analytics-preset-btn${isCustom ? " is-active" : ""}`}
              disabled={isLoading}
              onClick={() => setForceCustom(true)}
            >
              {opt.label}
            </button>
          );
        })}
      </fieldset>

      {isCustom && (
        <form
          className="analytics-filters-custom"
          onSubmit={(e) => {
            e.preventDefault();
            onApply(customFrom, customTo);
          }}
        >
          <label className="analytics-filter-field">
            <span>С</span>
            <input
              type="date"
              className="search-input analytics-date-input"
              value={customFrom}
              onChange={(e) => {
                setForceCustom(true);
                setCustomFrom(e.target.value);
              }}
            />
          </label>
          <label className="analytics-filter-field">
            <span>По</span>
            <input
              type="date"
              className="search-input analytics-date-input"
              value={customTo}
              onChange={(e) => {
                setForceCustom(true);
                setCustomTo(e.target.value);
              }}
            />
          </label>
          <button
            type="submit"
            className="btn btn-primary"
            disabled={isLoading}
          >
            Применить
          </button>
        </form>
      )}
    </div>
  );
}
