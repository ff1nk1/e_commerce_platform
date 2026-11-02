import { useState } from "react";
import { Link, useLoaderData, useNavigate } from "react-router-dom";
import { createOrder } from "../api/orders";
import { useCart } from "../cart/CartContext";
import { formatImageUrl, PLACEHOLDER_IMAGE } from "../utils/formatImageUrl";
import { formatMoney } from "../utils/money";
import "../styles/product.css";
import "../styles/orders.css";

export default function Product() {
  const { product, error } = useLoaderData();
  const navigate = useNavigate();
  const { addItem } = useCart();

  const [quantity, setQuantity] = useState(1);
  const [ordering, setOrdering] = useState(false);
  const [orderError, setOrderError] = useState("");
  const [cartMessage, setCartMessage] = useState("");

  const handleAddToCart = () => {
    if (!product) return;
    addItem(product, quantity);
    setCartMessage("Товар добавлен в корзину.");
    setOrderError("");
  };

  const handleOrder = async () => {
    if (!product || ordering) return;
    setOrdering(true);
    setOrderError("");
    setCartMessage("");
    try {
      const { data } = await createOrder(
        [{ product_id: product.id, quantity }],
        crypto.randomUUID(),
      );
      navigate(`/orders/${data.id}`);
    } catch (err) {
      setOrderError(
        err.response?.data?.message ||
          err.response?.data?.errors?.items?.[0] ||
          "Не удалось создать заказ. Нужен локальный снапшот товара в order_service.",
      );
    } finally {
      setOrdering(false);
    }
  };

  if (error || !product) {
    return (
      <div className="product-details-page">
        <div className="status-message error">{error || "Товар не найден"}</div>
        <button className="back-button" onClick={() => navigate(-1)}>
          ← Вернуться назад
        </button>
      </div>
    );
  }

  return (
    <div className="product-details-page">
      <div className="product-container">
        <button className="back-button" onClick={() => navigate(-1)}>
          ← Назад в каталог
        </button>

        <div className="product-full-card">
          <div className="product-image-container">
            <img
              src={formatImageUrl(product.image_url)}
              alt={product.name}
              className="product-image"
              onError={(e) => {
                e.target.onerror = null;
                e.target.src = PLACEHOLDER_IMAGE;
              }}
            />
          </div>

          <h1>{product.name}</h1>
          <div className="product-price-large">
            {formatMoney(product.price)}
          </div>

          {/* Бейджи с категорией и магазином */}
          <div className="product-badges">
            {product.category && (
              <span className="badge">Категория: {product.category.name}</span>
            )}
            {product.store && (
              <Link
                to={`/stores/${product.store.id || product.store_id}/products`}
                className="badge"
              >
                Магазин: {product.store.name}
              </Link>
            )}
            <span className="badge">Артикул: #{product.id}</span>
          </div>

          <div className="order-qty-row">
            <label htmlFor="order-qty">Количество</label>
            <input
              id="order-qty"
              type="number"
              min={1}
              value={quantity}
              onChange={(e) =>
                setQuantity(Math.max(1, Number(e.target.value) || 1))
              }
            />
            <button
              className="order-btn"
              onClick={handleAddToCart}
              type="button"
            >
              В корзину
            </button>
            <button
              className="order-btn order-btn-primary"
              onClick={handleOrder}
              disabled={ordering}
              type="button"
            >
              {ordering ? "Оформление..." : "Заказать"}
            </button>
          </div>
          {cartMessage && (
            <div className="status-message success">{cartMessage}</div>
          )}
          {orderError && (
            <div className="status-message error">{orderError}</div>
          )}

          {/* Блок характеристик (показываем, если есть attributes) */}
          {product.attributes && product.attributes.length > 0 && (
            <div className="product-attributes-block">
              <h3>Характеристики</h3>
              <ul className="attributes-list">
                {product.attributes.map((attr) => (
                  <li key={attr.id} className="attribute-item">
                    <span className="attr-name">{attr.name}</span>
                    <span className="attr-dots"></span>
                    <span className="attr-value">{attr.value}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Блок с описанием */}
          <div className="product-description-block">
            <h3>Описание</h3>
            <div className="product-description">
              {product.description ||
                "Описание для этого товара пока не добавлено."}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
