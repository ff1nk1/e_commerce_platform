import {
  Form,
  Link,
  useFetcher,
  useLoaderData,
  useSearchParams,
} from "react-router-dom";
import { formatShortDateTime } from "../utils/date";
import "../styles/admin.css";
import "../styles/notifications.css";

const STATUS_OPTIONS = [
  { value: "", label: "Все статусы" },
  { value: "sent", label: "Отправлено" },
  { value: "pending", label: "В очереди" },
  { value: "failed", label: "Ошибка" },
];

function recordId(log) {
  return String(log._id || log.id || "");
}

function shortenId(id) {
  if (!id) return "—";
  if (id.length <= 10) return id;
  return `${id.slice(0, 4)}…${id.slice(-4)}`;
}

const EMAIL_MAX_DISPLAY_LENGTH = 22;
const EMAIL_LOCAL_KEEP = 6;
const EMAIL_LOCAL_SOFT_MAX = 8;
const EMAIL_DOMAIN_KEEP = 10;
const EMAIL_DOMAIN_SOFT_MAX = 14;
const EMAIL_FALLBACK_PREFIX = 10;

function shortenEmail(email) {
  if (!email) return "—";
  if (email.length <= EMAIL_MAX_DISPLAY_LENGTH) return email;
  const at = email.indexOf("@");
  if (at <= 0) return `${email.slice(0, EMAIL_FALLBACK_PREFIX)}…`;
  const local = email.slice(0, at);
  const domain = email.slice(at);
  const shortLocal =
    local.length > EMAIL_LOCAL_SOFT_MAX
      ? `${local.slice(0, EMAIL_LOCAL_KEEP)}…`
      : local;
  const shortDomain =
    domain.length > EMAIL_DOMAIN_SOFT_MAX
      ? `${domain.slice(0, EMAIL_DOMAIN_KEEP)}…`
      : domain;
  return `${shortLocal}${shortDomain}`;
}

function shortenText(value, max = 18) {
  if (!value) return "—";
  if (value.length <= max) return value;
  return `${value.slice(0, max - 1)}…`;
}

export default function AdminNotifications() {
  const { logs, meta, filters, error } = useLoaderData();
  const [, setSearchParams] = useSearchParams();
  const resendFetcher = useFetcher();

  const page = meta?.current_page || 1;
  const lastPage = meta?.last_page || 1;
  const resendingId = resendFetcher.formData?.get("notification_id");
  const statusMessage = resendFetcher.data?.statusMessage;

  const goToPage = (next) => {
    if (next < 1 || next > lastPage) return;
    setSearchParams((prev) => {
      const params = new URLSearchParams(prev);
      params.set("page", String(next));
      return params;
    });
  };

  const handleResend = (log) => {
    const id = recordId(log);
    if (!id || log.status !== "failed") return;
    if (
      !window.confirm(
        "Отправить письмо повторно с ретраями (как при первичной отправке)?",
      )
    ) {
      return;
    }
    resendFetcher.submit(
      { notification_id: id, intent: "resend" },
      { method: "post", action: `/admin/notifications/${id}` },
    );
  };

  return (
    <div className="notifications-panel">
      <div className="admin-header">
        <h2>Журнал уведомлений</h2>
      </div>

      {error ? <div className="alert-banner alert-error">{error}</div> : null}
      {statusMessage ? (
        <div className={`alert-banner alert-${statusMessage.type}`}>
          {statusMessage.message}
        </div>
      ) : null}

      <Form method="get" className="filter-bar filter-bar-compact">
        <div className="filter-group">
          <select
            name="status"
            className="role-select filter-select"
            defaultValue={filters.status}
          >
            {STATUS_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>

          <input
            type="text"
            name="email"
            className="search-input"
            placeholder="Email..."
            defaultValue={filters.email}
          />

          <div className="date-input-wrapper">
            <span className="date-label">От</span>
            <input
              type="date"
              name="date_from"
              className="search-input date-input"
              defaultValue={filters.date_from}
            />
          </div>

          <div className="date-input-wrapper">
            <span className="date-label">До</span>
            <input
              type="date"
              name="date_to"
              className="search-input date-input"
              defaultValue={filters.date_to}
            />
          </div>

          <div className="filter-actions">
            <button type="submit" className="btn btn-primary">
              Применить
            </button>
            <Link to="/admin/notifications" className="btn btn-secondary">
              Сброс
            </Link>
          </div>
        </div>
      </Form>

      <div className="table-wrapper table-wrapper-fit">
        {logs.length === 0 ? (
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
                <th>Действия</th>
              </tr>
            </thead>
            <tbody>
              {logs.map((log) => {
                const id = recordId(log);
                return (
                  <tr key={id}>
                    <td className="id-cell" title={id}>
                      <Link to={`/admin/notifications/${id}`}>
                        {shortenId(id)}
                      </Link>
                    </td>
                    <td className="email-cell" title={log.email || ""}>
                      {shortenEmail(log.email)}
                    </td>
                    <td>
                      <span className={`status-badge status-${log.status}`}>
                        {log.status || "—"}
                      </span>
                    </td>
                    <td className="name-cell" title={log.name || ""}>
                      {shortenText(log.name, 14)}
                    </td>
                    <td className="event-cell" title={log.event_type || ""}>
                      {shortenText(log.event_type, 16)}
                    </td>
                    <td className="error-cell" title={log.error_message || ""}>
                      {shortenText(log.error_message, 20)}
                    </td>
                    <td className="time-cell">
                      {formatShortDateTime(log.time || log.created_at)}
                    </td>
                    <td className="actions-cell">
                      {log.status === "failed" && (
                        <button
                          type="button"
                          className="btn btn-secondary btn-compact"
                          disabled={resendingId === id}
                          onClick={() => handleResend(log)}
                        >
                          {resendingId === id ? "…" : "Resend"}
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>

      {logs.length > 0 && (
        <div className="pagination">
          <div className="pagination-info">
            {meta?.from}–{meta?.to} / {meta?.total}
          </div>
          <div className="pagination-controls">
            <button
              type="button"
              className="btn btn-secondary btn-compact"
              onClick={() => goToPage(page - 1)}
              disabled={page <= 1}
            >
              ←
            </button>
            <button
              type="button"
              className="btn btn-secondary btn-compact"
              onClick={() => goToPage(page + 1)}
              disabled={page >= lastPage}
            >
              →
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
