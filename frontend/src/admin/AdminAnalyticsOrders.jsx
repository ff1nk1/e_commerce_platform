import { useCallback, useEffect, useMemo, useState } from 'react'
import { useLoaderData, useSearchParams } from 'react-router-dom'
import { getOrdersAnalytics } from '../api/analytics'
import AnalyticsDateFilter from './AnalyticsDateFilter'
import { defaultAnalyticsRange } from './analyticsDates'
import AnalyticsBarChart from './charts/AnalyticsBarChart'
import AnalyticsLineChart from './charts/AnalyticsLineChart'

function formatRate(rate) {
  if (rate == null || Number.isNaN(Number(rate))) return '—'
  return `${(Number(rate) * 100).toFixed(1)}%`
}

export default function AdminAnalyticsOrders() {
  const initial = useLoaderData()
  const [searchParams, setSearchParams] = useSearchParams()
  const defaults = defaultAnalyticsRange()
  const [data, setData] = useState(initial)
  const [from, setFrom] = useState(searchParams.get('from') || defaults.from)
  const [to, setTo] = useState(searchParams.get('to') || defaults.to)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!searchParams.get('from') || !searchParams.get('to')) {
      setSearchParams({ from, to }, { replace: true })
    }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const fetchMetrics = useCallback(async (nextFrom, nextTo) => {
    setIsLoading(true)
    setError('')
    try {
      const params = {}
      if (nextFrom) params.from = nextFrom
      if (nextTo) params.to = nextTo
      const response = await getOrdersAnalytics(params)
      setData(response.data)
      const next = {}
      if (nextFrom) next.from = nextFrom
      if (nextTo) next.to = nextTo
      setSearchParams(next, { replace: true })
    } catch (err) {
      console.error('Orders analytics error:', err)
      setError(err.response?.data?.error || 'Не удалось загрузить метрики заказов')
    } finally {
      setIsLoading(false)
    }
  }, [setSearchParams])

  const funnel = data?.funnel || {}
  const conversions = data?.conversions || {}
  const byDay = data?.revenue?.by_day || []

  const revenueSeries = useMemo(
    () => (data?.revenue?.by_day || []).map((row) => ({
      date: row.date,
      amount: Number(row.amount) || 0,
    })),
    [data?.revenue?.by_day],
  )

  const funnelBars = useMemo(
    () => [
      { name: 'Created', value: data?.funnel?.created ?? 0 },
      { name: 'Paid', value: data?.funnel?.paid ?? 0 },
      { name: 'Cancelled', value: data?.funnel?.cancelled ?? 0 },
      { name: 'Open', value: data?.funnel?.open ?? 0 },
      { name: 'Completed', value: data?.funnel?.completed ?? 0 },
    ],
    [data?.funnel],
  )

  return (
    <div>
      <div className="admin-header">
        <h2>Аналитика: заказы</h2>
      </div>

      <AnalyticsDateFilter
        from={from}
        to={to}
        onFromChange={setFrom}
        onToChange={setTo}
        onApply={(nextFrom, nextTo) => fetchMetrics(nextFrom ?? from, nextTo ?? to)}
        isLoading={isLoading}
      />

      {error && <div className="analytics-error">{error}</div>}

      <h3 className="analytics-section-title">Воронка</h3>
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
          <span className="analytics-metric-label">Completed (shipped)</span>
          <strong className="analytics-metric-value">{funnel.completed ?? 0}</strong>
        </div>
      </div>

      <AnalyticsBarChart
        data={funnelBars}
        isLoading={isLoading}
        emptyText="Нет данных воронки"
        color="#2563eb"
      />

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

      <h3 className="analytics-section-title">Выручка</h3>
      <div className="analytics-metrics">
        <div className="analytics-metric-card">
          <span className="analytics-metric-label">Выручка (paid)</span>
          <strong className="analytics-metric-value">{data?.revenue?.total ?? '0.00'}</strong>
        </div>
        <div className="analytics-metric-card">
          <span className="analytics-metric-label">Средний чек</span>
          <strong className="analytics-metric-value">{data?.avg_order_value ?? '0.00'}</strong>
        </div>
      </div>

      <h3 className="analytics-section-title">Выручка по дням</h3>
      <AnalyticsLineChart
        data={revenueSeries}
        isLoading={isLoading}
        emptyText="Нет данных о выручке"
        series={[{ dataKey: 'amount', name: 'Выручка', stroke: '#059669' }]}
      />

      <div className="table-wrapper">
        {isLoading ? (
          <div className="table-loading">Загрузка данных...</div>
        ) : byDay.length === 0 ? (
          <div className="table-empty">Нет данных о выручке</div>
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th>Дата</th>
                <th>Сумма</th>
              </tr>
            </thead>
            <tbody>
              {byDay.map((row) => (
                <tr key={row.date}>
                  <td>{row.date}</td>
                  <td>{row.amount}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
