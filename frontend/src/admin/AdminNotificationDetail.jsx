import { useState } from "react";
import { Link, useLoaderData } from "react-router-dom";
import { getNotification, resendNotification } from "../api/notifications";

export default function AdminNotificationDetail() {
  const initial = useLoaderData();
  const [notification, setNotification] = useState(initial);
  const [isResending, setIsResending] = useState(false);
  const [resendStatus, setResendStatus] = useState(null);

  const notificationId = notification._id || notification.id;
  const canResend = notification.status === "failed";

  const handleResend = async () => {
    if (!canResend) return;
    setIsResending(true);
    setResendStatus({
      type: "pending",
      message: "Отправка с ретраями… это может занять несколько секунд",
    });

    try {
      const response = await resendNotification(notificationId);
      const data = response.data?.data;
      if (data) setNotification(data);
      const retries = data?.retries ?? "—";
      setResendStatus({
        type: "success",
        message: response.data?.message
          ? `${response.data.message} (попыток: ${retries})`
          : `Уведомление отправлено (попыток: ${retries})`,
      });
    } catch (error) {
      console.error("Resend error:", error);
      setResendStatus({
        type: "error",
        message:
          error.response?.data?.message || "Не удалось отправить повторно.",
      });
      try {
        const refreshed = await getNotification(notificationId);
        setNotification(refreshed.data?.data || refreshed.data);
      } catch {
        // keep current snapshot
      }
    } finally {
      setIsResending(false);
    }
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
          <span>{notification.time || notification.created_at || "—"}</span>
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
