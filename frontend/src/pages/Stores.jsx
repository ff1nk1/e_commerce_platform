import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { getStores } from '../api/catalog';
import CatalogSearchBar from '../components/CatalogSearchBar';
import '../styles/catalog.css';

export default function Stores() {
    const [stores, setStores] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        getStores()
            .then(response => {
                setStores(response.data.data || response.data || []);
            })
            .catch(error => console.error('Ошибка загрузки магазинов:', error))
            .finally(() => setLoading(false));
    }, []);

    return (
        <div className="catalog-page">
            <div className="catalog-card">
                <div className="catalog-header">
                    <h1>Магазины</h1>
                    <p>Выберите магазин, чтобы посмотреть его товары</p>
                    <CatalogSearchBar />
                </div>

                {loading ? (
                    <div className="status-message">Загрузка магазинов...</div>
                ) : stores.length === 0 ? (
                    <div className="status-message">Магазины не найдены.</div>
                ) : (
                    <ul className="category-list">
                        {stores.map(store => (
                            <li key={store.id} className="category-item">
                                <Link to={`/stores/${store.id}/products`} className="category-link">
                                    {store.name}
                                    <span className="arrow">→</span>
                                </Link>
                                {store.description ? (
                                    <p className="status-message" style={{ margin: '0.25rem 0 0' }}>
                                        {store.description}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
