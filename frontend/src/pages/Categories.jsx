import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import { getCategories } from "../api/catalog";
import CatalogSearchBar from "../components/CatalogSearchBar";
import PageShell from "../components/PageShell";
import "../styles/catalog.css";

export default function Categories() {
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    getCategories()
      .then((response) => {
        setCategories(response.data);
      })
      .catch((error) => console.error("Ошибка загрузки категорий:", error))
      .finally(() => setLoading(false));
  }, []);

  return (
    <PageShell title="Каталог" subtitle="Выберите интересующий вас раздел">
      <CatalogSearchBar />
      {loading ? (
        <div className="status-message">Загрузка категорий...</div>
      ) : (
        <ul className="category-list">
          {categories.map((category) => (
            <li key={category.id} className="category-item">
              <Link
                to={`/categories/${category.slug}`}
                className="category-link"
              >
                {category.name}
                <span className="arrow">→</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </PageShell>
  );
}
