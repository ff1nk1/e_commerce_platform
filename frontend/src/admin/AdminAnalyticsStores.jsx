import { useCallback, useEffect, useMemo, useState } from 'react'
import { useLoaderData, useSearchParams } from 'react-router-dom'
import { getStoresAnalytics } from '../api/analytics'
import AnalyticsDateFilter from './AnalyticsDateFilter'
import { defaultAnalyticsRange } from './analyticsDates'
import AnalyticsBarChart from './charts/AnalyticsBarChart'

function formatRate(rate) {
  if (rate == null || Number.isNaN(Number(rate))) return '—'
  return `${(Number(rate) * 100).toFixed(1)}%`
}

function displayName(row) {
  const name = row?.name
  if (name && !/^#\d+$/.test(name)) return name
  if (name) return name
  return row?.id != null ? `#${row.id}` : '—'
}

function optionLabel(opt) {
  return opt?.name || (opt?.id != null ? `#${opt.id}` : '—')
}

export default function AdminAnalyticsStores() {
  const initial = useLoaderData()
  const [searchParams, setSearchParams] = useSearchParams()
  const defaults = defaultAnalyticsRange()
  const [data, setData] = useState(initial)
  const [from, setFrom] = useState(searchParams.get('from') || defaults.from)
  const [to, setTo] = useState(searchParams.get('to') || defaults.to)
  const [storeId, setStoreId] = useState(searchParams.get('store_id') || '')
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!searchParams.get('from') || !searchParams.get('to')) {
      const next = { from, to }
      if (storeId) next.store_id = storeId
      setSearchParams(next, { replace: true })
    }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const fetchMetrics = useCallback(async (nextFrom, nextTo, nextStoreId) => {
    setIsLoading(true)
    setError('')
    try {
      const params = {}
      if (nextFrom) params.from = nextFrom
      if (nextTo) params.to = nextTo
      if (nextStoreId) params.store_id = nextStoreId
      const response = await getStoresAnalytics(params)
      setData(response.data)
      const next = {}
      if (nextFrom) next.from = nextFrom
      if (nextTo) next.to = nextTo
      if (nextStoreId) next.store_id = nextStoreId
      setSearchParams(next, { replace: true })
    } catch (err) {
      console.error('Stores analytics error:', err)
      setError(err.response?.data?.error || 'Не удалось загрузить метрики по магазинам')
    } finally {
      setIsLoading(false)
    }
  }, [setSearchParams])

  const isDetail = Boolean(storeId)
  const items = data?.items || []
  const options = data?.options || []
  const funnel = data?.funnel || {}
  const conversions = data?.conversions || {}

  const selectOptions = useMemo(() => {
    const byId = new Map()
    for (const opt of options) {
      byId.set(String(opt.id), opt)
    }
    for (const row of items) {
      if (!byId.has(String(row.id))) {
        byId.set(String(row.id), { id: row.id, name: row.name })
      }
    }
    if (storeId && !byId.has(storeId) && data?.name) {
      byId.set(storeId, { id: Number(storeId), name: data.name })
    }
    return Array.from(byId.values()).sort((a, b) =>
      optionLabel(a).localeCompare(optionLabel(b), 'ru'),
    )
  }, [options, items, storeId, data?.name])

  const rankingBars = useMemo(
    () => items.map((row) => ({
      name: displayName(row),
      value: Number(row.orders_count) || 0,
    })),
    [items],
  )

  const onStoreChange = (e) => {
    const nextId = e.target.value
    setStoreId(nextId)
    fetchMetrics(from, to, nextId)
  }

  const title = isDetail ? (data?.name || displayName({ id: storeId })) : null
  const showIdHint = isDetail && data?.name && !/^#\d+$/.test(data.name)

  return (
    <div>
      <div className="admin-header">
        <h2>Аналитика: магазины</h2>
      </div>

      <AnalyticsDateFilter
        from={from}
        to={to}
        onFromChange={setFrom}
        onToChange={setTo}
        onApply={(nextFrom, nextTo) => fetchMetrics(nextFrom ?? from, nextTo ?? to, storeId)}
        isLoading={isLoading}
      />

      <div className="analytics-filters">
        <label className="analytics-filter-field">
          Магазин
          <select
            className="search-input analytics-date-input"
            value={storeId}
            onChange={onStoreChange}
            disabled={isLoading}
          >
            <option value="">Все магазины</option>
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
          {showIdHint && <p className="analytics-muted">id: {storeId}</p>}
          <div className="analytics-metrics">
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Заказов</span>
              <strong className="analytics-metric-value">{data?.orders_count ?? 0}</strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Товаров (шт.)</span>
              <strong className="analytics-metric-value">{data?.items_quantity ?? 0}</strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Сумма позиций</span>
              <strong className="analytics-metric-value">{data?.line_amount ?? '0.00'}</strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Оплачено (позиции)</span>
              <strong className="analytics-metric-value">{data?.paid_line_amount ?? '0.00'}</strong>
            </div>
          </div>

          <h3 className="analytics-section-title">Воронка заказов магазина</h3>
          <div className="analytics-metrics">
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Created</span>
              <strong className="analytics-metric-value">{funnel.created ?? 0}</strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Paid</span>
              <strong className="analytics-metric-value">{funnel.paid ?? 0}</strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Cancelled</span>
              <strong className="analytics-metric-value">{funnel.cancelled ?? 0}</strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Open</span>
              <strong className="analytics-metric-value">{funnel.open ?? 0}</strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Completed</span>
              <strong className="analytics-metric-value">{funnel.completed ?? 0}</strong>
            </div>
          </div>

          <h3 className="analytics-section-title">Конверсия</h3>
          <div className="analytics-metrics">
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Created → Paid</span>
              <strong className="analytics-metric-value">{formatRate(conversions.created_to_paid)}</strong>
            </div>
            <div className="analytics-metric-card">
              <span className="analytics-metric-label">Created → Cancelled</span>
              <strong className="analytics-metric-value">{formatRate(conversions.created_to_cancelled)}</strong>
            </div>
          </div>
        </>
      ) : (
        <>
          <h3 className="analytics-section-title">Топ магазинов</h3>
          <AnalyticsBarChart
            data={rankingBars}
            isLoading={isLoading}
            emptyText="Нет данных по магазинам"
            color="#0f766e"
          />

          <div className="table-wrapper">
            {isLoading ? (
              <div className="table-loading">Загрузка данных...</div>
            ) : items.length === 0 ? (
              <div className="table-empty">Нет данных по магазинам</div>
            ) : (
              <table className="admin-table">
                <thead>
                  <tr>
                    <th>Магазин</th>
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
                            setStoreId(String(row.id))
                            fetchMetrics(from, to, String(row.id))
                          }}
                        >
                          {displayName(row)}
                        </button>
                        {row.name && !/^#\d+$/.test(row.name) ? (
                          <span className="analytics-id-hint"> #{row.id}</span>
                        ) : null}
                      </td>
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
  )
}
