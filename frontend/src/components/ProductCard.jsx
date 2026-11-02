import { Link } from "react-router-dom";
import { formatImageUrl, PLACEHOLDER_IMAGE } from "../utils/formatImageUrl";
import { formatMoney } from "../utils/money";

const CLASS_VARIANTS = {
  default: {
    card: "product-card",
    imageContainer: "product-image-container",
    image: "product-image",
    info: "product-info",
    title: "product-title",
    price: "product-price",
  },
  assistant: {
    card: "assistant-product-card",
    imageContainer: null,
    image: "assistant-product-image",
    info: "assistant-product-info",
    title: "assistant-product-name",
    price: "assistant-product-price",
  },
};

/**
 * Shared product tile used by search, category, store and assistant grids.
 *
 * @param {{
 *   product: object,
 *   variant?: 'default' | 'assistant',
 *   inStock?: boolean|null,
 * }} props
 */
export default function ProductCard({ product, variant = "default", inStock }) {
  const classes = CLASS_VARIANTS[variant] ?? CLASS_VARIANTS.default;
  const productId = product.id ?? product.product_id;
  const stock = inStock === undefined ? (product.in_stock ?? null) : inStock;

  const image = (
    <img
      src={formatImageUrl(product.image_url)}
      alt={product.name || ""}
      className={classes.image}
      onError={(e) => {
        e.target.onerror = null;
        e.target.src = PLACEHOLDER_IMAGE;
      }}
    />
  );

  return (
    <Link to={`/products/${productId}`} className={classes.card}>
      {classes.imageContainer ? (
        <div className={classes.imageContainer}>{image}</div>
      ) : (
        image
      )}
      <div className={classes.info}>
        <h3 className={classes.title}>{product.name}</h3>
        <div className={classes.price}>{formatMoney(product.price)}</div>
        {stock != null ? (
          <div
            className={
              variant === "assistant"
                ? `assistant-product-stock ${stock ? "in-stock" : "out-of-stock"}`
                : `product-stock ${stock ? "in" : "out"}`
            }
          >
            {stock ? "В наличии" : "Нет в наличии"}
          </div>
        ) : null}
      </div>
    </Link>
  );
}
