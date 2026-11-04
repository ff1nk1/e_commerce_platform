import { useEffect, useRef, useState } from "react";
import { getOrder } from "../api/orders";

const RESERVE_POLL_MS = 2000;
// ~30s
const RESERVE_MAX_ATTEMPTS = 15;

/**
 * Polls the order while the warehouse reserve is still pending, until the
 * status changes or the timeout is reached. Kept in one place so OrderDetail
 * only deals with presentation and derived state.
 *
 * @param {{ order: object|null, active: boolean, onReserved?: (order: object) => void }} params
 */
export function useReservePolling({ order, active, onReserved }) {
  const [polledOrder, setPolledOrder] = useState(null);
  const [timedOut, setTimedOut] = useState(false);
  const [nonce, setNonce] = useState(0);
  const attemptsRef = useRef(0);
  const onReservedRef = useRef(onReserved);

  useEffect(() => {
    onReservedRef.current = onReserved;
  }, [onReserved]);

  const orderId = order?.id ?? null;

  useEffect(() => {
    if (!active || !orderId) return undefined;

    attemptsRef.current = 0;

    const timer = setInterval(async () => {
      attemptsRef.current += 1;
      try {
        const { data } = await getOrder(orderId);
        setPolledOrder(data);
        if (data.status !== "pending") {
          setTimedOut(false);
          clearInterval(timer);
          onReservedRef.current?.(data);
          return;
        }
      } catch {
        // count the attempt anyway
      }

      if (attemptsRef.current >= RESERVE_MAX_ATTEMPTS) {
        clearInterval(timer);
        setTimedOut(true);
      }
    }, RESERVE_POLL_MS);

    return () => clearInterval(timer);
    // `nonce` is a manual retry trigger: bumping it re-runs the poll even though
    // `active`/`orderId` are unchanged.
    // eslint-disable-next-line react/exhaustive-effect-dependencies
  }, [active, orderId, nonce]);

  return {
    order: polledOrder,
    setOrder: setPolledOrder,
    timedOut,
    setTimedOut,
    retry: () => {
      setTimedOut(false);
      setPolledOrder(null);
      setNonce((n) => n + 1);
    },
  };
}
