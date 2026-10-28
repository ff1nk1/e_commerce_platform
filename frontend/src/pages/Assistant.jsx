import { useCallback, useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { getStores } from "../api/catalog";
import {
  deleteConversation,
  getConversation,
  sendChatMessage,
} from "../api/recommendations";
import { formatImageUrl, PLACEHOLDER_IMAGE } from "../utils/formatImageUrl";
import "../styles/assistant.css";

const STORAGE_KEY = "assistant_conversation_id";
const STORE_STORAGE_KEY = "assistant_store_id";

function loadStoredConversationId() {
  try {
    return sessionStorage.getItem(STORAGE_KEY) || null;
  } catch {
    return null;
  }
}

function persistConversationId(id) {
  try {
    if (id) {
      sessionStorage.setItem(STORAGE_KEY, id);
    } else {
      sessionStorage.removeItem(STORAGE_KEY);
    }
  } catch {
    // ignore storage errors
  }
}

function loadStoredStoreId() {
  try {
    return sessionStorage.getItem(STORE_STORAGE_KEY) || "";
  } catch {
    return "";
  }
}

function persistStoreId(id) {
  try {
    if (id) {
      sessionStorage.setItem(STORE_STORAGE_KEY, String(id));
    } else {
      sessionStorage.removeItem(STORE_STORAGE_KEY);
    }
  } catch {
    // ignore storage errors
  }
}

export default function Assistant() {
  const [messages, setMessages] = useState([]);
  const [conversationId, setConversationId] = useState(null);
  const [input, setInput] = useState("");
  const [loading, setLoading] = useState(false);
  const [restoring, setRestoring] = useState(true);
  const [error, setError] = useState("");
  const [stores, setStores] = useState([]);
  const [storeId, setStoreId] = useState(() => loadStoredStoreId());
  const listRef = useRef(null);
  const inputRef = useRef(null);

  const scrollToBottom = useCallback(() => {
    requestAnimationFrame(() => {
      if (listRef.current) {
        listRef.current.scrollTop = listRef.current.scrollHeight;
      }
    });
  }, []);

  useEffect(() => {
    scrollToBottom();
  }, [messages, loading, scrollToBottom]);

  useEffect(() => {
    let cancelled = false;

    getStores()
      .then((response) => {
        if (cancelled) return;
        const nestedList = response.data?.data;
        const bareList = response.data;
        const list = Array.isArray(nestedList)
          ? nestedList
          : Array.isArray(bareList)
            ? bareList
            : [];
        setStores(list);
      })
      .catch((err) => {
        console.error(err);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    let cancelled = false;

    async function restore() {
      const storedId = loadStoredConversationId();
      if (!storedId) {
        if (!cancelled) setRestoring(false);
        return;
      }

      try {
        const { data } = await getConversation(storedId);
        if (cancelled) return;

        setConversationId(data.conversation_id);
        setMessages(
          (data.messages || []).map((msg) => ({
            id: msg.id,
            role: msg.role,
            content: msg.content,
            products: Array.isArray(msg.products) ? msg.products : null,
          })),
        );
      } catch (err) {
        if (cancelled) return;
        if (err.response?.status === 404) {
          persistConversationId(null);
          setConversationId(null);
          setMessages([]);
        } else {
          console.error(err);
          setError("Не удалось загрузить историю диалога.");
        }
      } finally {
        if (!cancelled) setRestoring(false);
      }
    }

    restore();
    return () => {
      cancelled = true;
    };
  }, []);

  const onStoreChange = (event) => {
    const next = event.target.value;
    setStoreId(next);
    persistStoreId(next);
  };

  const sendMessage = async (event) => {
    event.preventDefault();
    const text = input.trim();
    if (!text || loading) return;

    setError("");
    setInput("");
    setLoading(true);

    const optimisticId = `local-user-${Date.now()}`;
    setMessages((prev) => [
      ...prev,
      { id: optimisticId, role: "user", content: text, products: null },
    ]);

    try {
      const buildPayload = (id) => {
        const payload = { message: text };
        if (id) {
          payload.conversation_id = id;
        }
        if (storeId) {
          payload.store_id = Number(storeId);
        }
        return payload;
      };

      let data;
      try {
        ({ data } = await sendChatMessage(buildPayload(conversationId)));
      } catch (err) {
        // Expired/foreign id → drop stale storage and start a fresh conversation once.
        if (err.response?.status === 404 && conversationId) {
          persistConversationId(null);
          setConversationId(null);
          setMessages([
            { id: optimisticId, role: "user", content: text, products: null },
          ]);
          ({ data } = await sendChatMessage(buildPayload(null)));
        } else {
          throw err;
        }
      }

      const nextId = data.conversation_id;
      setConversationId(nextId);
      persistConversationId(nextId);

      setMessages((prev) => [
        ...prev,
        {
          id: `local-assistant-${Date.now()}`,
          role: "assistant",
          content: data.answer || "",
          products: data.products || [],
        },
      ]);
    } catch (err) {
      console.error(err);
      setMessages((prev) => prev.filter((m) => m.id !== optimisticId));
      setInput(text);
      setError(
        err.response?.data?.message ||
          "Не удалось получить ответ. Попробуйте ещё раз.",
      );
    } finally {
      setLoading(false);
      inputRef.current?.focus();
    }
  };

  const startNewDialog = async () => {
    setError("");
    const idToDelete = conversationId;
    setMessages([]);
    setConversationId(null);
    persistConversationId(null);
    setInput("");
    inputRef.current?.focus();

    if (!idToDelete) return;

    try {
      await deleteConversation(idToDelete);
    } catch (err) {
      if (err.response?.status !== 404) {
        console.error(err);
      }
    }
  };

  return (
    <div className="assistant-page">
      <div className="assistant-container">
        <div className="assistant-card">
          <div className="assistant-header">
            <div>
              <h1>ИИ помощник</h1>
              <p>Спросите, что подобрать — подскажу товары из каталога</p>
            </div>
            <div className="assistant-header-actions">
              <label className="assistant-store-label">
                <span>Магазин</span>
                <select
                  className="assistant-store-select"
                  value={storeId}
                  onChange={onStoreChange}
                  disabled={loading || restoring}
                >
                  <option value="">Все магазины</option>
                  {stores.map((store) => (
                    <option key={store.id} value={store.id}>
                      {store.name}
                    </option>
                  ))}
                </select>
              </label>
              <button
                type="button"
                className="assistant-btn assistant-btn-secondary"
                onClick={startNewDialog}
                disabled={loading || restoring}
              >
                Новый диалог
              </button>
            </div>
          </div>

          {error && <div className="status-message error">{error}</div>}

          <div className="assistant-messages" ref={listRef}>
            {restoring ? (
              <div className="status-message">Загрузка диалога...</div>
            ) : messages.length === 0 && !loading ? (
              <div className="assistant-empty">
                Напишите, что ищете — например, «синие кроссовки до 5000 ₽».
              </div>
            ) : (
              messages.map((msg) => (
                <div
                  key={msg.id}
                  className={`assistant-bubble assistant-bubble-${msg.role}`}
                >
                  <div className="assistant-bubble-label">
                    {msg.role === "user" ? "Вы" : "Помощник"}
                  </div>
                  <div className="assistant-bubble-text">{msg.content}</div>

                  {msg.role === "assistant" &&
                    Array.isArray(msg.products) &&
                    msg.products.length > 0 && (
                      <div className="assistant-products">
                        {msg.products.map((product) => {
                          const productId = product.product_id;
                          return (
                            <Link
                              key={productId}
                              to={`/products/${productId}`}
                              className="assistant-product-card"
                            >
                              <img
                                src={formatImageUrl(product.image_url)}
                                alt={product.name || ""}
                                className="assistant-product-image"
                                onError={(e) => {
                                  e.target.onerror = null;
                                  e.target.src = PLACEHOLDER_IMAGE;
                                }}
                              />
                              <div className="assistant-product-info">
                                <div className="assistant-product-name">
                                  {product.name}
                                </div>
                                {product.price != null && (
                                  <div className="assistant-product-price">
                                    {product.price} ₽
                                  </div>
                                )}
                                <div
                                  className={
                                    product.in_stock
                                      ? "assistant-product-stock in-stock"
                                      : "assistant-product-stock out-of-stock"
                                  }
                                >
                                  {product.in_stock
                                    ? "В наличии"
                                    : "Нет в наличии"}
                                </div>
                              </div>
                            </Link>
                          );
                        })}
                      </div>
                    )}
                </div>
              ))
            )}

            {loading && (
              <div className="assistant-bubble assistant-bubble-assistant">
                <div className="assistant-bubble-label">Помощник</div>
                <div className="assistant-bubble-text assistant-typing">
                  Думаю...
                </div>
              </div>
            )}
          </div>

          <form className="assistant-form" onSubmit={sendMessage}>
            <textarea
              ref={inputRef}
              className="assistant-input"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder="Опишите, что хотите найти..."
              rows={2}
              disabled={loading || restoring}
              onKeyDown={(e) => {
                if (e.key === "Enter" && !e.shiftKey) {
                  e.preventDefault();
                  sendMessage(e);
                }
              }}
            />
            <button
              type="submit"
              className="assistant-btn assistant-btn-primary"
              disabled={loading || restoring || !input.trim()}
            >
              Отправить
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
