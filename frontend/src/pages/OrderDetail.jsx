import { useCallback, useEffect, useRef, useState } from "react";
import {
  Link,
  useLoaderData,
  useNavigate,
  useRevalidator,
  useSearchParams,
} from "react-router-dom";
import { cancelOrder, getOrder, payOrder } from "../api/orders";
import "../styles/orders.css";

const RESERVE_POLL_MS = 2000;
const RESERVE_MAX_ATTEMPTS = 15; // ~30s

function noticeForOrder(order) {
  if (order?.status === "cancelled") {
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
  if (order?.status === "payment_failed") {
    return { message: "", error: "Оплата не прошла. Резерв будет снят." };
  }
  return null;
}

export default function OrderDetail() {
  const { order: initialOrder } = useLoaderData();
  const [order, setOrder] = useState(initialOrder);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [reserveTimedOut, setReserveTimedOut] = useState(false);
  const [pollNonce, setPollNonce] = useState(0);
  const navigate = useNavigate();
  const revalidator = useRevalidator();
  const [searchParams, setSearchParams] = useSearchParams();
  const autoPayStarted = useRef(false);
  const skipAutoPay = useRef(searchParams.get("canceled") === "1");
  const prevStatus = useRef(initialOrder?.status);
  const orderIdRef = useRef(initialOrder?.id);
  const pollAttempts = useRef(0);
  const payIdempotencyKey = useRef(null);

  const canPay = order?.status === "awaiting_payment";
  const canCancel =
    order?.status === "pending" || order?.status === "awaiting_payment";
  const isReserving = order?.status === "pending" && !reserveTimedOut;
  const paidFlag = searchParams.get("paid") === "1";
  const canceledFlag = searchParams.get("canceled") === "1";
  const urlFlag = paidFlag ? "paid" : canceledFlag ? "canceled" : "";
  const [handledUrlFlag, setHandledUrlFlag] = useState("");
  if (urlFlag && urlFlag !== handledUrlFlag) {
    setHandledUrlFlag(urlFlag);
    setMessage(
      urlFlag === "paid"
        ? "Оплата прошла успешно. Статус обновится после подтверждения Stripe."
        : "Оплата отменена. Можно попробовать снова.",
    );
    setError("");
  }

  const [seenInitial, setSeenInitial] = useState(initialOrder);
  if (initialOrder !== seenInitial) {
    setSeenInitial(initialOrder);
    setOrder(initialOrder);
    if (initialOrder?.id !== seenInitial?.id) {
      setBusy(false);
      setMessage("");
      setError("");
      setReserveTimedOut(false);
    } else if (initialOrder?.status !== "pending") {
      setReserveTimedOut(false);
    }
  }

  useEffect(() => {
    const orderChanged = initialOrder?.id !== orderIdRef.current;
    orderIdRef.current = initialOrder?.id;
    if (orderChanged) {
      prevStatus.current = initialOrder?.status;
      autoPayStarted.current = false;
      skipAutoPay.current = searchParams.get("canceled") === "1";
    }
  }, [initialOrder, searchParams]);

  useEffect(() => {
    if (!paidFlag && !canceledFlag) return;
    if (canceledFlag) {
      skipAutoPay.current = true;
      autoPayStarted.current = false;
      payIdempotencyKey.current = null;
    }
    if (paidFlag) {
      revalidator.revalidate();
    }
    const next = new URLSearchParams(searchParams);
    next.delete("paid");
    next.delete("canceled");
    setSearchParams(next, { replace: true });
  }, [paidFlag, canceledFlag, searchParams, setSearchParams, revalidator]);

  useEffect(() => {
    if (!isReserving || !order?.id) return undefined;

    pollAttempts.current = 0;

    const timer = setInterval(async () => {
      pollAttempts.current += 1;
      try {
        const { data } = await getOrder(order.id);
        setOrder(data);
        if (data.status !== "pending") {
          setReserveTimedOut(false);
          revalidator.revalidate();
          return;
        }
      } catch {
        // count attempt anyway
      }

      if (pollAttempts.current >= RESERVE_MAX_ATTEMPTS) {
        clearInterval(timer);
        setReserveTimedOut(true);
        setMessage("");
        setError(
          "Резерв не подтвердился. Проверьте, что работают Kafka outbox/consumers (order + catalog), или отмените заказ.",
        );
      }
    }, RESERVE_POLL_MS);

    return () => clearInterval(timer);
  }, [isReserving, order?.id, pollNonce, revalidator]);

  const startCheckout = useCallback(async () => {
    if (!order?.id || busy) return;
    setBusy(true);
    setError("");
    try {
      if (!payIdempotencyKey.current) {
        payIdempotencyKey.current = crypto.randomUUID();
      }
      const { data } = await payOrder(order.id, payIdempotencyKey.current);
      if (data?.checkout_url) {
        window.location.href = data.checkout_url;
        return;
      }
      setError("Не удалось получить ссылку на оплату.");
      autoPayStarted.current = false;
    } catch (err) {
      setError(err.response?.data?.message || "Не удалось начать оплату.");
      autoPayStarted.current = false;
    } finally {
      setBusy(false);
    }
  }, [busy, order]);

  useEffect(() => {
    const previous = prevStatus.current;
    const next = order?.status;
    prevStatus.current = next;

    const justReserved = previous === "pending" && next === "awaiting_payment";
    const shouldSkipAutoCheckout =
      skipAutoPay.current || busy || autoPayStarted.current;
    const canStartAutoCheckout = justReserved && !shouldSkipAutoCheckout;
    if (!canStartAutoCheckout) return undefined;

    autoPayStarted.current = true;
    const timer = setTimeout(() => {
      setReserveTimedOut(false);
      setMessage("Товар зарезервирован. Переходим к оплате…");
      void startCheckout();
    }, 0);

    return () => clearTimeout(timer);
  }, [order?.status, busy, startCheckout]);

  const handlePay = async () => {
    if (!canPay || busy) return;
    skipAutoPay.current = false;
    autoPayStarted.current = true;
    await startCheckout();
  };

  const handleRetryReservePoll = () => {
    setReserveTimedOut(false);
    setError("");
    setPollNonce((n) => n + 1);
  };

  const handleCancel = async () => {
    if (!canCancel || busy) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const { data } = await cancelOrder(order.id);
      setOrder(data);
      setReserveTimedOut(false);
      revalidator.revalidate();
    } catch (err) {
      setError(err.response?.data?.message || "Не удалось отменить заказ.");
    } finally {
      setBusy(false);
    }
  };

  const statusNotice = noticeForOrder(order);
  const shownMessage = statusNotice
    ? statusNotice.message
    : isReserving
      ? "Ожидаем резерв склада…"
      : message;
  const shownError = statusNotice
    ? statusNotice.error
    : isReserving
      ? ""
      : error;

  if (!order) {
    return (
      <div className="orders-page">
        <div className="orders-container">
          <div className="status-message error">Заказ не найден</div>
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
              <span className={`order-status ${order.status}`}>
                {order.status}
              </span>
              {" · "}
              {order.total_amount} ₽
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
                    {item.quantity} × {item.unit_price} ₽
                  </span>
                </div>
              </li>
            ))}
          </ul>

          {(canPay || canCancel || reserveTimedOut) && (
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
              {reserveTimedOut && order.status === "pending" && (
                <button
                  className="order-btn"
                  onClick={handleRetryReservePoll}
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
