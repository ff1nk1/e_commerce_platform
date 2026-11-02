import { useEffect, useMemo, useRef, useState } from "react";
import {
  Link,
  useActionData,
  useLoaderData,
  useNavigate,
  useNavigation,
  useRevalidator,
  useSearchParams,
  useSubmit,
} from "react-router-dom";
import { formatMoney } from "../utils/money";
import { useReservePolling } from "../orders/useReservePolling";
import "../styles/orders.css";

const AUTO_CHECKOUT_DELAY_MS = 0;

/** Human-readable notice derived from the order status. */
export function noticeForOrder(order) {
  if (!order) return null;
  if (order.status === "cancelled") {
    if (order.cancel_reason === "user_cancelled") {
      return { message: "Этот заказ был отменён вами", error: "" };
    }
    if (order.cancel_reason === "insufficient_stock") {
      return {
        message: "",
        error: "Не удалось зарезервировать товар. Заказ отменён.",
      };
    }
    return { message: "Заказ отменён.", error: "" };
  }
  if (order.status === "payment_failed") {
    return { message: "", error: "Оплата не прошла. Резерв будет снят." };
  }
  return null;
}

/** Redirect the browser to Stripe Checkout. */
function goToCheckout(url) {
  if (typeof window !== "undefined") window.location.href = url;
}

export default function OrderDetail() {
  const { order: loaderOrder, error: loaderError } = useLoaderData();
  const actionData = useActionData();
  const submit = useSubmit();
  const navigation = useNavigation();
  const revalidator = useRevalidator();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  const paidFlag = searchParams.get("paid") === "1";
  const canceledFlag = searchParams.get("canceled") === "1";

  const busy = navigation.state !== "idle";
  const orderId = loaderOrder?.id ?? null;

  const [localMessage, setLocalMessage] = useState("");
  const [localError, setLocalError] = useState("");

  // Preserve exact auto-checkout behavior (once per order, skipped after cancel).
  const autoPayStarted = useRef(false);
  const skipAutoPay = useRef(canceledFlag);
  const prevStatus = useRef(loaderOrder?.status ?? null);
  const prevOrderId = useRef(orderId);
  const payIdempotencyKey = useRef(null);

  // Polling yields the freshest order; the loader remains the fallback.
  const {
    order: polledOrder,
    timedOut,
    retry,
  } = useReservePolling({
    order: loaderOrder,
    active: loaderOrder?.status === "pending",
    onReserved: () => revalidator.revalidate(),
  });

  const order = polledOrder ?? loaderOrder;
  const status = order?.status ?? null;
  const reserving = status === "pending" && !timedOut;

  // Reset transient state when the order identity changes.
  useEffect(() => {
    if (orderId !== prevOrderId.current) {
      prevOrderId.current = orderId;
      autoPayStarted.current = false;
      skipAutoPay.current = canceledFlag;
      payIdempotencyKey.current = null;
      setLocalMessage("");
      setLocalError("");
    }
  }, [orderId, canceledFlag]);

  // URL flags: success / cancellation notices and one-shot param cleanup.
  useEffect(() => {
    if (!paidFlag && !canceledFlag) return;
    if (canceledFlag) {
      skipAutoPay.current = true;
      autoPayStarted.current = false;
      payIdempotencyKey.current = null;
    }
    setLocalMessage(
      paidFlag
        ? "Оплата прошла успешно. Статус обновится после подтверждения Stripe."
        : "Оплата отменена. Можно попробовать снова.",
    );
    setLocalError("");
    const next = new URLSearchParams(searchParams);
    next.delete("paid");
    next.delete("canceled");
    setSearchParams(next, { replace: true });
  }, [paidFlag, canceledFlag, searchParams, setSearchParams]);

  const startCheckout = () => {
    if (!payIdempotencyKey.current) {
      payIdempotencyKey.current = crypto.randomUUID();
    }
    setLocalError("");
    setLocalMessage("Товар зарезервирован. Переходим к оплате…");
    submit(
      { intent: "pay", idempotency_key: payIdempotencyKey.current },
      { method: "post", replace: true },
    );
  };

  // React to action results: redirect to Stripe or show the error.
  useEffect(() => {
    if (!actionData) return;
    if (actionData.checkoutUrl) {
      goToCheckout(actionData.checkoutUrl);
    } else if (actionData.error) {
      setLocalError(actionData.error);
      autoPayStarted.current = false;
    }
  }, [actionData]);

  // Auto-checkout: once the reserve lands (status flips to awaiting_payment),
  // start the Stripe flow exactly once.
  useEffect(() => {
    const previous = prevStatus.current;
    prevStatus.current = status;
    const justReserved =
      previous === "pending" && status === "awaiting_payment";
    if (
      !justReserved ||
      skipAutoPay.current ||
      busy ||
      autoPayStarted.current
    ) {
      return undefined;
    }
    autoPayStarted.current = true;
    const timer = setTimeout(startCheckout, AUTO_CHECKOUT_DELAY_MS);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, busy]);

  const handlePay = () => {
    skipAutoPay.current = false;
    autoPayStarted.current = true;
    startCheckout();
  };

  const handleCancel = () => {
    setLocalMessage("");
    setLocalError("");
    submit({ intent: "cancel" }, { method: "post", replace: true });
  };

  const canPay = status === "awaiting_payment";
  const canCancel = status === "pending" || status === "awaiting_payment";

  const shownMessage = useMemo(() => {
    const notice = noticeForOrder(order);
    if (notice) return notice.message;
    if (reserving) return "Ожидаем резерв склада…";
    return localMessage;
  }, [order, reserving, localMessage]);

  const shownError = useMemo(() => {
    const notice = noticeForOrder(order);
    if (notice) return notice.error;
    if (reserving) return "";
    if (timedOut) {
      return "Резерв не подтвердился. Проверьте, что работают Kafka outbox/consumers (order + catalog), или отмените заказ.";
    }
    return localError;
  }, [order, reserving, timedOut, localError]);

  if (loaderError || !order) {
    return (
      <div className="orders-page">
        <div className="orders-container">
          <div className="status-message error">
            {loaderError || "Заказ не найден"}
          </div>
          <button className="order-btn" onClick={() => navigate("/orders")}>
            К списку
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="orders-page">
      <div className="orders-container">
        <div className="orders-nav">
          <Link to="/orders">← Мои заказы</Link>
        </div>
        <div className="orders-card">
          <div className="orders-header">
            <h1>Заказ #{order.id}</h1>
            <p>
              <span className={`order-status ${status}`}>{status}</span>
              {" · "}
              {formatMoney(order.total_amount)}
            </p>
          </div>

          {shownMessage && (
            <div className="status-message success">{shownMessage}</div>
          )}
          {shownError && (
            <div className="status-message error">{shownError}</div>
          )}

          <h3>Позиции</h3>
          <ul className="orders-list">
            {(order.items || []).map((item) => (
              <li key={item.id} className="order-item">
                <div className="order-item-row">
                  <strong>
                    {item.product_snapshot?.name || `Товар #${item.product_id}`}
                  </strong>
                  <span>
                    {item.quantity} × {formatMoney(item.unit_price)}
                  </span>
                </div>
              </li>
            ))}
          </ul>

          {(canPay || canCancel || timedOut) && (
            <div className="order-actions">
              {canPay && (
                <button
                  className="order-btn order-btn-primary"
                  onClick={handlePay}
                  disabled={busy}
                >
                  {busy ? "Переход к оплате..." : "Оплатить"}
                </button>
              )}
              {timedOut && status === "pending" && (
                <button
                  className="order-btn"
                  onClick={() => {
                    setLocalError("");
                    retry();
                  }}
                  disabled={busy}
                >
                  Проверить снова
                </button>
              )}
              {canCancel && (
                <button
                  className="order-btn order-btn-danger"
                  onClick={handleCancel}
                  disabled={busy}
                >
                  {busy ? "Отмена..." : "Отменить заказ"}
                </button>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
