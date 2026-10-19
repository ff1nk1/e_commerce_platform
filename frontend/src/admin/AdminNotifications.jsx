import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { getNotifications, resendNotification } from '../api/notifications'

const STATUS_OPTIONS = [
  { value: '', label: 'Все статусы' },
  { value: 'sent', label: 'Отправлено' },
  { value: 'pending', label: 'В очереди' },
  { value: 'failed', label: 'Ошибка' },
]

function formatDate(dateString) {
  if (!dateString) return '—'
  const value = typeof dateString === 'number'
    ? dateString * (dateString < 1e12 ? 1000 : 1)
    : dateString
  return new Date(value).toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function recordId(log) {
  return String(log._id || log.id || '')
}

function shortenId(id) {
  if (!id) return '—'
  if (id.length <= 10) return id
  return `${id.slice(0, 4)}…${id.slice(-4)}`
}

function shortenEmail(email) {
  if (!email) return '—'
  if (email.length <= 22) return email
  const at = email.indexOf('@')
  if (at <= 0) return `${email.slice(0, 10)}…`
  const local = email.slice(0, at)
  const domain = email.slice(at)
  const shortLocal = local.length > 8 ? `${local.slice(0, 6)}…` : local
  return `${shortLocal}${domain.length > 14 ? `${domain.slice(0, 10)}…` : domain}`
}

function shortenText(value, max = 18) {
  if (!value) return '—'
  if (value.length <= max) return value
  return `${value.slice(0, max - 1)}…`
}

export default function AdminNotifications() {
  const [filters, setFilters] = useState({
    status: '',
    email: '',
    date_from: '',
    date_to: '',
  })
  const [page, setPage] = useState(1)
  const [logs, setLogs] = useState([])
  const [pagination, setPagination] = useState({
    currentPage: 1,
    lastPage: 1,
    total: 0,
    from: 0,
    to: 0,
  })
  const [isLoading, setIsLoading] = useState(false)
  const [resendingId, setResendingId] = useState(null)
  const [statusMessage, setStatusMessage] = useState(null)

  const fetchNotifications = useCallback(async (targetPage = 1, currentFilters = filters) => {
    setIsLoading(true)
    try {
      const params = {
        page: targetPage,
        per_page: 15,
        ...(currentFilters.status && { status: currentFilters.status }),
        ...(currentFilters.email && { email: currentFilters.email }),
        ...(currentFilters.date_from && { date_from: currentFilters.date_from }),
        ...(currentFilters.date_to && { date_to: currentFilters.date_to }),
      }

      const response = await getNotifications(params)
      const data = response.data

      setLogs(data.data || [])
      setPagination({
        currentPage: data.current_page || 1,
        lastPage: data.last_page || 1,
        total: data.total || 0,
        from: data.from || 0,
        to: data.to || 0,
      })
      setPage(data.current_page || targetPage)
    } catch (error) {
      console.error('Fetch error:', error)
      setStatusMessage({ type: 'error', message: 'Не удалось загрузить уведомления' })
    } finally {
      setIsLoading(false)
    }
  }, [filters])

  useEffect(() => {
    fetchNotifications(1)
  }, [])

  const handleInputChange = (field, value) => {
    setFilters((prev) => ({ ...prev, [field]: value }))
  }

  const handleApplyFilters = (e) => {
    e.preventDefault()
    setPage(1)
    fetchNotifications(1, filters)
  }

  const handleResetFilters = () => {
    const resetState = { status: '', email: '', date_from: '', date_to: '' }
    setFilters(resetState)
    setPage(1)
    fetchNotifications(1, resetState)
  }

  const handlePageChange = (newPage) => {
    if (newPage >= 1 && newPage <= pagination.lastPage) {
      fetchNotifications(newPage, filters)
    }
  }

  const handleResend = async (log) => {
    const id = recordId(log)
    if (!id || log.status !== 'failed') return
    if (!window.confirm('Отправить письмо повторно с ретраями (как при первичной отправке)?')) return

    setResendingId(id)
    setStatusMessage({
      type: 'pending',
      message: 'Отправка с ретраями… это может занять несколько секунд',
    })
    try {
      const response = await resendNotification(id)
      const data = response.data?.data
      const retries = data?.retries ?? '—'
      setStatusMessage({
        type: 'success',
        message: response.data?.message
          ? `${response.data.message} (попыток: ${retries})`
          : `Уведомление отправлено (попыток: ${retries})`,
      })
      await fetchNotifications(page, filters)
    } catch (error) {
      console.error('Resend error:', error)
      setStatusMessage({
        type: 'error',
        message: error.response?.data?.message || 'Не удалось отправить повторно',
      })
      await fetchNotifications(page, filters)
    } finally {
      setResendingId(null)
    }
  }

  return (
    <div className="notifications-panel">
      <div className="admin-header">
        <h2>Журнал уведомлений</h2>
      </div>

      {statusMessage && (
        <div className={`alert-banner alert-${statusMessage.type}`}>{statusMessage.message}</div>
      )}

      <form className="filter-bar filter-bar-compact" onSubmit={handleApplyFilters}>
        <div className="filter-group">
          <select
            className="role-select filter-select"
            value={filters.status}
            onChange={(e) => handleInputChange('status', e.target.value)}
          >
            {STATUS_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>

          <input
            type="text"
            className="search-input"
            placeholder="Email..."
            value={filters.email}
            onChange={(e) => handleInputChange('email', e.target.value)}
          />

          <div className="date-input-wrapper">
            <span className="date-label">От</span>
            <input
              type="date"
              className="search-input date-input"
              value={filters.date_from}
              onChange={(e) => handleInputChange('date_from', e.target.value)}
            />
          </div>

          <div className="date-input-wrapper">
            <span className="date-label">До</span>
            <input
              type="date"
              className="search-input date-input"
              value={filters.date_to}
              onChange={(e) => handleInputChange('date_to', e.target.value)}
            />
          </div>

          <div className="filter-actions">
            <button type="submit" className="btn btn-primary" disabled={isLoading}>
              Применить
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={handleResetFilters}
              disabled={isLoading}
            >
              Сброс
            </button>
          </div>
        </div>
      </form>

      <div className="table-wrapper table-wrapper-fit">
        {isLoading ? (
          <div className="table-loading">Загрузка данных...</div>
        ) : logs.length === 0 ? (
          <div className="table-empty">Записи не найдены</div>
        ) : (
          <table className="admin-table admin-table-compact">
            <thead>
              <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Статус</th>
                <th>Имя</th>
                <th>Событие</th>
                <th>Ошибка</th>
                <th>Время</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {logs.map((log) => {
                const id = recordId(log)
                return (
                  <tr key={id}>
                    <td className="id-cell" title={id}>
                      <Link to={`/admin/notifications/${id}`}>{shortenId(id)}</Link>
                    </td>
                    <td className="email-cell" title={log.email || ''}>
                      {shortenEmail(log.email)}
                    </td>
                    <td>
                      <span className={`status-badge status-${log.status}`}>
                        {log.status || '—'}
                      </span>
                    </td>
                    <td className="name-cell" title={log.name || ''}>
                      {shortenText(log.name, 14)}
                    </td>
                    <td className="event-cell" title={log.event_type || ''}>
                      {shortenText(log.event_type, 16)}
                    </td>
                    <td className="error-cell" title={log.error_message || ''}>
                      {shortenText(log.error_message, 20)}
                    </td>
                    <td className="time-cell">{formatDate(log.time || log.created_at)}</td>
                    <td className="actions-cell">
                      {log.status === 'failed' && (
                        <button
                          type="button"
                          className="btn btn-secondary btn-compact"
                          disabled={resendingId === id}
                          onClick={() => handleResend(log)}
                        >
                          {resendingId === id ? '…' : 'Resend'}
                        </button>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>

      {!isLoading && logs.length > 0 && (
        <div className="pagination">
          <div className="pagination-info">
            {pagination.from}–{pagination.to} / {pagination.total}
          </div>
          <div className="pagination-controls">
            <button
              type="button"
              className="btn btn-secondary btn-compact"
              onClick={() => handlePageChange(page - 1)}
              disabled={page <= 1}
            >
              ←
            </button>
            <button
              type="button"
              className="btn btn-secondary btn-compact"
              onClick={() => handlePageChange(page + 1)}
              disabled={page >= pagination.lastPage}
            >
              →
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
