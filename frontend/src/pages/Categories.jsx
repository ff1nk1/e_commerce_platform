import { Link, useLoaderData } from "react-router-dom";
import CatalogSearchBar from "../components/CatalogSearchBar";
import PageShell from "../components/PageShell";
import "../styles/catalog.css";

export default function Categories() {
  const { categories, error } = useLoaderData();

  return (
    <PageShell title="Каталог" subtitle="Выберите интересующий вас раздел">
      <CatalogSearchBar />
      {error ? (
        <div className="status-message error">{error}</div>
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
