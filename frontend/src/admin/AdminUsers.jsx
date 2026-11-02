import { useLoaderData, useSearchParams, useFetcher } from "react-router-dom";
import "../styles/admin.css";

export default function AdminUsers() {
  const { users, meta, roles } = useLoaderData();
  const [, setSearchParams] = useSearchParams();
  const fetcher = useFetcher();

  const page = meta?.current_page || 1;
  const lastPage = meta?.last_page || 1;
  const updatingId = fetcher.formData?.get("user_id");
  const status = fetcher.data?.status;

  const goToPage = (next) => {
    if (next < 1 || next > lastPage) return;
    setSearchParams((prev) => {
      const nextParams = new URLSearchParams(prev);
      nextParams.set("page", String(next));
      return nextParams;
    });
  };

  return (
    <div>
      <div className="admin-header">
        <h2>Пользователи</h2>
      </div>

      {status && (
        <div className={`alert-banner alert-${status.type}`}>
          {status.message}
        </div>
      )}

      <div className="table-wrapper">
        {users.length === 0 ? (
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
                  <td>{user.name || "—"}</td>
                  <td>{user.email || "—"}</td>
                  <td>
                    <fetcher.Form method="post">
                      <input type="hidden" name="user_id" value={user.id} />
                      <select
                        className="role-select"
                        name="role_id"
                        aria-label={`Роль пользователя ${user.name || user.id}`}
                        value={user.role_id || user.role?.id || ""}
                        disabled={updatingId === String(user.id)}
                        onChange={(e) => e.currentTarget.form?.requestSubmit()}
                      >
                        {roles.map((role) => (
                          <option key={role.id} value={role.id}>
                            {role.name || role.slug}
                          </option>
                        ))}
                      </select>
                    </fetcher.Form>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {users.length > 0 && (
        <div className="pagination">
          <div className="pagination-info">
            Показано {meta?.from}–{meta?.to} из {meta?.total}
          </div>
          <div className="pagination-controls">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => goToPage(page - 1)}
              disabled={page <= 1}
            >
              Назад
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => goToPage(page + 1)}
              disabled={page >= lastPage}
            >
              Вперед
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
