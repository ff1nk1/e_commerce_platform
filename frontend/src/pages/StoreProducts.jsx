import { useState, useEffect } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { getStoreProducts } from '../api/catalog';
import { formatImageUrl, PLACEHOLDER_IMAGE } from '../utils/formatImageUrl';
import '../styles/catalog.css';

export default function StoreProducts() {
    const { storeId } = useParams();
    const [searchParams, setSearchParams] = useSearchParams();

    const [store, setStore] = useState(null);
    const [products, setProducts] = useState([]);
    const [pagination, setPagination] = useState({ current_page: 1, last_page: 1 });
    const [loading, setLoading] = useState(true);

    const [statusFilter, setStatusFilter] = useState(searchParams.get('status') || 'all');

    const applyFilters = (e) => {
        e.preventDefault();
        const next = { page: 1 };
        if (statusFilter && statusFilter !== 'all') {
            next.status = statusFilter;
        }
        setSearchParams(next);
    };

    const handlePageChange = (newPage) => {
        setSearchParams(prevParams => {
            const next = new URLSearchParams(prevParams);
            next.set('page', String(newPage));
            return next;
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    useEffect(() => {
        setLoading(true);
        const params = Object.fromEntries(searchParams.entries());
        if (params.status === 'all') {
            delete params.status;
        }

        getStoreProducts(storeId, params)
            .then(response => {
                setStore(response.data.store || null);
                setProducts(response.data.products?.data || []);
                setPagination({
                    current_page: response.data.products?.current_page || 1,
                    last_page: response.data.products?.last_page || 1,
                });
            })
            .catch(error => console.error('Ошибка загрузки товаров магазина:', error))
            .finally(() => setLoading(false));
    }, [storeId, searchParams]);

    const storeName = store?.name || `Магазин #${storeId}`;

    return (
        <div className="catalog-page">
            <div className="catalog-card products-container">
                <div className="catalog-header">
                    <h1>{storeName}</h1>
                    <p>Каталог товаров продавца</p>
                    <p>
                        <Link to="/stores" className="category-link">← Все магазины</Link>
                    </p>
                </div>

                <form className="filters-form" onSubmit={applyFilters}>
                    <div className="filters-wrapper">
                        <select
                            className="profile-input filter-select"
                            value={statusFilter}
                            onChange={e => setStatusFilter(e.target.value)}
                        >
                            <option value="all">Все товары</option>
                            <option value="in_stock">В наличии</option>
                        </select>
                        <button className="save-button filter-button" type="submit">Фильтровать</button>
                    </div>
                </form>

                {loading ? (
                    <div className="status-message">Загрузка...</div>
                ) : (
                    <>
                        <div className="products-grid">
                            {products.length === 0 ? (
                                <div className="status-message error" style={{ gridColumn: '1 / -1' }}>
                                    Товары не найдены.
                                </div>
                            ) : null}

                            {products.map(product => (
                                <Link
                                    key={product.id}
                                    to={`/products/${product.id}`}
                                    className="product-card"
                                    style={{ textDecoration: 'none', color: 'inherit' }}
                                >
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
                                    <div className="product-info">
                                        <h3 className="product-title">{product.name}</h3>
                                        <div className="product-price">{product.price} ₽</div>
                                    </div>
                                </Link>
                            ))}
                        </div>

                        {pagination.last_page > 1 && (
                            <div className="pagination">
                                <button
                                    className="pagination-button"
                                    disabled={pagination.current_page === 1}
                                    onClick={() => handlePageChange(pagination.current_page - 1)}
                                >
                                    ← Назад
                                </button>

                                <span className="pagination-info">
                                    Страница {pagination.current_page} из {pagination.last_page}
                                </span>

                                <button
                                    className="pagination-button"
                                    disabled={pagination.current_page === pagination.last_page}
                                    onClick={() => handlePageChange(pagination.current_page + 1)}
                                >
                                    Вперед →
                                </button>
                            </div>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
