import { useCallback, useState } from 'react'
import { useLoaderData } from 'react-router-dom'
import { getAdminUsers, updateUserRole } from '../api/admin'

export default function AdminUsers() {
  const initial = useLoaderData()
  const [roles] = useState(() => {
    const raw = initial.roles
    return Array.isArray(raw) ? raw : raw?.data || []
  })
  const [users, setUsers] = useState(initial.users?.data || [])
  const [pagination, setPagination] = useState({
    currentPage: initial.users?.current_page || 1,
    lastPage: initial.users?.last_page || 1,
    total: initial.users?.total || 0,
    from: initial.users?.from || 0,
    to: initial.users?.to || 0,
  })
  const [page, setPage] = useState(initial.users?.current_page || 1)
  const [isLoading, setIsLoading] = useState(false)
  const [updatingId, setUpdatingId] = useState(null)
  const [status, setStatus] = useState(null)

  const fetchUsers = useCallback(async (targetPage = 1) => {
    setIsLoading(true)
    setStatus(null)
    try {
      const response = await getAdminUsers({
        page: targetPage,
        per_page: 15,
      })
      const data = response.data
      setUsers(data.data || [])
      setPagination({
        currentPage: data.current_page || 1,
        lastPage: data.last_page || 1,
        total: data.total || 0,
        from: data.from || 0,
        to: data.to || 0,
      })
      setPage(data.current_page || targetPage)
    } catch (error) {
      console.error('Users fetch error:', error)
      setStatus({ type: 'error', message: 'Не удалось загрузить пользователей' })
    } finally {
      setIsLoading(false)
    }
  }, [])

  const handlePageChange = (newPage) => {
    if (newPage >= 1 && newPage <= pagination.lastPage) {
      fetchUsers(newPage)
    }
  }

  const handleRoleChange = async (userId, roleId) => {
    setUpdatingId(userId)
    setStatus(null)
    try {
      await updateUserRole(userId, roleId)
      setUsers((prev) =>
        prev.map((u) => {
          if (u.id !== userId) return u
          const role = roles.find((r) => r.id === Number(roleId))
          return { ...u, role_id: Number(roleId), role: role || u.role }
        })
      )
      setStatus({ type: 'success', message: 'Роль обновлена' })
    } catch (error) {
      console.error('Role change error:', error)
      setStatus({
        type: 'error',
        message: error.response?.data?.message || 'Не удалось сменить роль',
      })
    } finally {
      setUpdatingId(null)
    }
  }

  return (
    <div>
      <div className="admin-header">
        <h2>Пользователи</h2>
      </div>

      {status && (
        <div className={`alert-banner alert-${status.type}`}>{status.message}</div>
      )}

      <div className="table-wrapper">
        {isLoading ? (
          <div className="table-loading">Загрузка данных...</div>
        ) : users.length === 0 ? (
          <div className="table-empty">Пользователи не найдены</div>
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Имя</th>
                <th>Email</th>
                <th>Роль</th>
              </tr>
            </thead>
            <tbody>
              {users.map((user) => (
                <tr key={user.id}>
                  <td className="id-cell">{user.id}</td>
                  <td>{user.name || '—'}</td>
                  <td>{user.email || '—'}</td>
                  <td>
                    <select
                      className="role-select"
                      value={user.role_id || user.role?.id || ''}
                      disabled={updatingId === user.id}
                      onChange={(e) => handleRoleChange(user.id, e.target.value)}
                    >
                      {roles.map((role) => (
                        <option key={role.id} value={role.id}>
                          {role.name || role.slug}
                        </option>
                      ))}
                    </select>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {!isLoading && users.length > 0 && (
        <div className="pagination">
          <div className="pagination-info">
            Показано {pagination.from}–{pagination.to} из {pagination.total}
          </div>
          <div className="pagination-controls">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => handlePageChange(page - 1)}
              disabled={page <= 1}
            >
              Назад
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => handlePageChange(page + 1)}
              disabled={page >= pagination.lastPage}
            >
              Вперед
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
