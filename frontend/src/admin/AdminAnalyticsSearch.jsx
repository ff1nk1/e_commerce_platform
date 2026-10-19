import { useCallback, useEffect, useMemo, useState } from 'react'
import { useLoaderData, useSearchParams } from 'react-router-dom'
import { getSearchAnalytics } from '../api/analytics'
import AnalyticsDateFilter from './AnalyticsDateFilter'
import { defaultAnalyticsRange } from './analyticsDates'
import AnalyticsBarChart from './charts/AnalyticsBarChart'

function formatRate(rate) {
  if (rate == null || Number.isNaN(Number(rate))) return '—'
  return `${(Number(rate) * 100).toFixed(1)}%`
}

function QueryTable({ title, rows, emptyText, isLoading }) {
  return (
    <>
      <h3 className="analytics-section-title">{title}</h3>
      <div className="table-wrapper">
        {isLoading ? (
          <div className="table-loading">Загрузка данных...</div>
        ) : rows.length === 0 ? (
          <div className="table-empty">{emptyText}</div>
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th>Запрос</th>
                <th>Кол-во</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={`${row.query}-${row.count}`}>
                  <td>{row.query || '(empty)'}</td>
                  <td>{row.count}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  )
}

export default function AdminAnalyticsSearch() {
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
      const params = { limit: 20 }
      if (nextFrom) params.from = nextFrom
      if (nextTo) params.to = nextTo
      const response = await getSearchAnalytics(params)
      setData(response.data)
      const next = {}
      if (nextFrom) next.from = nextFrom
      if (nextTo) next.to = nextTo
      setSearchParams(next, { replace: true })
    } catch (err) {
      console.error('Search analytics error:', err)
      setError(err.response?.data?.error || 'Не удалось загрузить метрики поиска')
    } finally {
      setIsLoading(false)
    }
  }, [setSearchParams])

  const popularBars = useMemo(
    () =>
      (data?.popular_queries || []).slice(0, 10).map((row) => ({
        name: row.query || '(empty)',
        value: Number(row.count) || 0,
      })),
    [data?.popular_queries],
  )

  return (
    <div>
      <div className="admin-header">
        <h2>Поисковые запросы</h2>
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

      <div className="analytics-metrics">
        <div className="analytics-metric-card">
          <span className="analytics-metric-label">Всего поисков</span>
          <strong className="analytics-metric-value">{data?.total_searches ?? 0}</strong>
        </div>
        <div className="analytics-metric-card">
          <span className="analytics-metric-label">Без результатов</span>
          <strong className="analytics-metric-value">{data?.zero_result_count ?? 0}</strong>
        </div>
        <div className="analytics-metric-card">
          <span className="analytics-metric-label">Доля пустых</span>
          <strong className="analytics-metric-value">{formatRate(data?.zero_result_rate)}</strong>
        </div>
      </div>

      <h3 className="analytics-section-title">Топ запросов</h3>
      <AnalyticsBarChart
        data={popularBars}
        layout="horizontal"
        isLoading={isLoading}
        emptyText="Нет популярных запросов"
        color="#7c3aed"
      />

      <QueryTable
        title="Популярные запросы"
        rows={data?.popular_queries || []}
        emptyText="Нет популярных запросов"
        isLoading={isLoading}
      />

      <QueryTable
        title="Запросы без результатов"
        rows={data?.zero_result_queries || []}
        emptyText="Нет запросов без результатов"
        isLoading={isLoading}
      />
    </div>
  )
}
