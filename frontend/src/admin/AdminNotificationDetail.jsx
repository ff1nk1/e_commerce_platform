import { Link, useFetcher, useLoaderData } from "react-router-dom";
import { formatDateTime } from "../utils/date";
import "../styles/admin.css";
import "../styles/notifications.css";

export default function AdminNotificationDetail() {
  const initial = useLoaderData();
  const fetcher = useFetcher();

  // Adopt the freshly-resent record once the action resolves; otherwise fall
  // back to the loader value (derived in render, no state-syncing effect).
  const notification = fetcher.data?.notification ?? initial;

  const notificationId = notification._id || notification.id;
  const canResend = notification.status === "failed";
  const isResending = fetcher.state !== "idle";
  const resendStatus = fetcher.data?.statusMessage;

  const handleResend = () => {
    if (!canResend) return;
    fetcher.submit({ intent: "resend" }, { method: "post" });
  };

  return (
    <div>
      <div className="admin-header">
        <h2>Детали уведомления</h2>
        <div className="header-actions">
          {canResend && (
            <button
              type="button"
              onClick={handleResend}
              disabled={isResending}
              className="btn btn-primary resend-btn"
            >
              {isResending ? "Отправка..." : "Отправить ещё раз"}
            </button>
          )}
          <Link to="/admin/notifications" className="btn btn-secondary">
            ← Назад к списку
          </Link>
        </div>
      </div>

      {resendStatus && (
        <div className={`alert-banner alert-${resendStatus.type}`}>
          {resendStatus.message}
        </div>
      )}

      <div className="detail-content">
        <div className="detail-row">
          <strong>ID записи:</strong> <span>{notificationId}</span>
        </div>

        <div className="detail-row">
          <strong>Message ID:</strong>{" "}
          <span>{notification.message_id || "—"}</span>
        </div>

        <div className="detail-row">
          <strong>Событие:</strong>{" "}
          <span>{notification.event_type || "—"}</span>
        </div>

        <div className="detail-row">
          <strong>Имя получателя:</strong>{" "}
          <span>{notification.name || "—"}</span>
        </div>

        <div className="detail-row">
          <strong>Email:</strong> <span>{notification.email}</span>
        </div>

        <div className="detail-row">
          <strong>Статус:</strong>{" "}
          <span className={`status-badge status-${notification.status}`}>
            {notification.status}
          </span>
        </div>

        <div className="detail-row">
          <strong>Попыток отправки (Retries):</strong>{" "}
          <span>{notification.retries ?? 0}</span>
        </div>

        <div className="detail-row">
          <strong>Отправлено в DLQ:</strong>{" "}
          <span className="dlq-status">
            {notification.moved_to_dlq ? "Да (Ошибка)" : "Нет"}
          </span>
        </div>

        <div className="detail-row">
          <strong>Время:</strong>{" "}
          <span>
            {formatDateTime(notification.time || notification.created_at)}
          </span>
        </div>

        {notification.error_message && (
          <div className="error-box">
            <strong className="error-title">Ошибка отправки:</strong>
            <p className="error-text">{notification.error_message}</p>
          </div>
        )}

        <div className="detail-row payload-container">
          <strong>Payload (содержимое Kafka сообщения):</strong>
          <pre className="payload-pre">
            {JSON.stringify(notification.payload, null, 2)}
          </pre>
        </div>
      </div>
    </div>
  );
}
