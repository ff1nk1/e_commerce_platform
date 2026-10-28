import { Link } from "react-router-dom";
import { useCart } from "./CartContext";

export default function CartNavLink({ className = "" }) {
  const { totalCount } = useCart();
  return (
    <Link to="/cart" className={className}>
      Корзина{totalCount > 0 ? ` (${totalCount})` : ""}
    </Link>
  );
}
