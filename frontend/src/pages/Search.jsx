import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { searchProducts } from '../api/catalog';
import useSearchAutocomplete from '../hooks/useSearchAutocomplete';
import { formatImageUrl, PLACEHOLDER_IMAGE } from '../utils/formatImageUrl';
import '../styles/catalog.css';

function parseAttrParams(searchParams) {
    const attrs = {};
    for (const [key, value] of searchParams.entries()) {
        const match = key.match(/^attr\[(.+)\]$/);
        if (!match) continue;
        const name = match[1];
        if (!attrs[name]) attrs[name] = [];
        if (value && !attrs[name].includes(value)) {
            attrs[name].push(value);
        }
    }
    return attrs;
}

function buildRequestParams(searchParams) {
    const params = {};
    const q = searchParams.get('q');
    if (q) params.q = q;

    const categoryIds = searchParams.getAll('category_id');
    if (categoryIds.length === 1) {
        params.category_id = categoryIds[0];
    } else if (categoryIds.length > 1) {
        params.category_id = categoryIds;
    }

    ['min_price', 'max_price', 'in_stock', 'sort', 'page', 'per_page'].forEach((key) => {
        const value = searchParams.get(key);
        if (value !== null && value !== '') {
            params[key] = value;
        }
    });

    const attrs = parseAttrParams(searchParams);
    if (Object.keys(attrs).length > 0) {
        params.attr = attrs;
    }

    return params;
}

export default function Search() {
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const [products, setProducts] = useState([]);
    const [facets, setFacets] = useState({
        categories: [],
        price_ranges: [],
        availability: [],
        attributes: {},
    });
    const [pagination, setPagination] = useState({
        current_page: 1,
        last_page: 1,
        total: 0,
    });
    const [loading, setLoading] = useState(true);
    const [queryInput, setQueryInput] = useState(searchParams.get('q') || '');
    const {
        suggestions,
        open: autocompleteOpen,
        setOpen: setAutocompleteOpen,
        close: closeAutocomplete,
    } = useSearchAutocomplete(queryInput);

    const selectedCategoryIds = useMemo(
        () => searchParams.getAll('category_id').map(String),
        [searchParams]
    );
    const selectedAttrs = useMemo(() => parseAttrParams(searchParams), [searchParams]);
    const selectedInStock = searchParams.get('in_stock');
    const selectedPriceRange = searchParams.get('price_range');

    useEffect(() => {
        setQueryInput(searchParams.get('q') || '');
    }, [searchParams]);

    useEffect(() => {
        setLoading(true);
        searchProducts(buildRequestParams(searchParams))
            .then((response) => {
                const data = response.data;
                setProducts(data.products?.data || []);
                setFacets(
                    data.facets || {
                        categories: [],
                        price_ranges: [],
                        availability: [],
                        attributes: {},
                    }
                );
                setPagination({
                    current_page: data.products?.current_page || 1,
                    last_page: data.products?.last_page || 1,
                    total: data.products?.total || 0,
                });
            })
            .catch((error) => console.error('Ошибка поиска:', error))
            .finally(() => setLoading(false));
    }, [searchParams]);

    const updateParams = (mutator) => {
        setSearchParams((prev) => {
            const next = new URLSearchParams(prev);
            mutator(next);
            next.delete('page');
            return next;
        });
    };

    const clearAllSearchParams = () => {
        setQueryInput('');
        setSearchParams({}, { replace: true });
    };

    const hasActiveFilters = useMemo(() => {
        for (const key of searchParams.keys()) {
            if (key !== 'q') return true;
        }
        return false;
    }, [searchParams]);

    const clearAllFilters = () => {
        const q = searchParams.get('q');
        if (q) {
            setSearchParams({ q }, { replace: true });
        } else {
            setSearchParams({}, { replace: true });
        }
    };

    const onSearchSubmit = (e) => {
        e.preventDefault();
        closeAutocomplete();
        const q = queryInput.trim();
        if (!q) {
            clearAllSearchParams();
            return;
        }
        updateParams((next) => {
            next.set('q', q);
        });
    };

    const onQueryChange = (e) => {
        const value = e.target.value;
        setQueryInput(value);
        setAutocompleteOpen(true);
        if (value === '') {
            clearAllSearchParams();
            closeAutocomplete();
        }
    };

    const onSelectSuggestion = (item) => {
        closeAutocomplete();
        navigate(`/products/${item.id}`);
    };

    const toggleCategory = (id) => {
        const idStr = String(id);
        updateParams((next) => {
            const current = next.getAll('category_id');
            next.delete('category_id');
            const exists = current.includes(idStr);
            const nextCategoryIds = exists
                ? current.filter((v) => v !== idStr)
                : [...current, idStr];
            nextCategoryIds.forEach((v) => next.append('category_id', v));
        });
    };

    const toggleInStock = (value) => {
        updateParams((next) => {
            const nextValue = value ? '1' : '0';
            if (next.get('in_stock') === nextValue) next.delete('in_stock');
            else next.set('in_stock', nextValue);
        });
    };

    const applyPriceRange = (range) => {
        updateParams((next) => {
            if (selectedPriceRange === range.key) {
                next.delete('price_range');
                next.delete('min_price');
                next.delete('max_price');
                return;
            }
            next.set('price_range', range.key);
            if (range.from !== null && range.from !== undefined) next.set('min_price', String(range.from));
            else next.delete('min_price');
            if (range.to !== null && range.to !== undefined) {
                const exclusiveMaxPrice = range.to - 0.01;
                next.set('max_price', String(exclusiveMaxPrice));
            } else {
                next.delete('max_price');
            }
            // for 5000+ keep only min
            if (range.to === null || range.to === undefined) {
                next.delete('max_price');
            }
        });
    };

    const toggleAttr = (name, value) => {
        updateParams((next) => {
            const key = `attr[${name}]`;
            const current = next.getAll(key);
            next.delete(key);
            const exists = current.includes(value);
            const nextAttrValues = exists
                ? current.filter((v) => v !== value)
                : [...current, value];
            nextAttrValues.forEach((v) => next.append(key, v));
        });
    };

    const handlePageChange = (newPage) => {
        setSearchParams((prev) => {
            const next = new URLSearchParams(prev);
            next.set('page', String(newPage));
            return next;
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const setSort = (sort) => {
        updateParams((next) => {
            if (sort) next.set('sort', sort);
            else next.delete('sort');
        });
    };

    return (
        <div className="catalog-page">
            <div className="catalog-card search-layout">
                <div className="catalog-header">
                    <h1>Поиск</h1>
                    <form className="filters-form search-main-form" onSubmit={onSearchSubmit}>
                        <div className="filters-wrapper">
                            <div
                                className="search-autocomplete"
                                onBlur={(e) => {
                                    if (!e.currentTarget.contains(e.relatedTarget)) {
                                        closeAutocomplete();
                                    }
                                }}
                            >
                                <input
                                    className="profile-input filter-input"
                                    type="search"
                                    placeholder="Что ищете?"
                                    value={queryInput}
                                    onChange={onQueryChange}
                                    onFocus={() => {
                                        if (suggestions.length > 0) setAutocompleteOpen(true);
                                    }}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Escape') closeAutocomplete();
                                    }}
                                    aria-autocomplete="list"
                                    autoComplete="off"
                                />
                                {autocompleteOpen && suggestions.length > 0 ? (
                                    <ul className="search-autocomplete-list" role="listbox">
                                        {suggestions.map((item) => (
                                            <li key={item.id} role="option">
                                                <button
                                                    type="button"
                                                    className="search-autocomplete-item"
                                                    onMouseDown={(e) => e.preventDefault()}
                                                    onClick={() => onSelectSuggestion(item)}
                                                >
                                                    <img
                                                        src={formatImageUrl(item.image_url)}
                                                        alt=""
                                                        className="search-autocomplete-thumb"
                                                        onError={(e) => {
                                                            e.target.onerror = null;
                                                            e.target.src = PLACEHOLDER_IMAGE;
                                                        }}
                                                    />
                                                    <span className="search-autocomplete-name">
                                                        {item.name}
                                                    </span>
                                                    {item.price != null ? (
                                                        <span className="search-autocomplete-price">
                                                            {item.price} ₽
                                                        </span>
                                                    ) : null}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </div>
                            <select
                                className="profile-input filter-select"
                                value={searchParams.get('sort') || (searchParams.get('q') ? 'relevance' : 'new')}
                                onChange={(e) => setSort(e.target.value)}
                            >
                                <option value="relevance">По релевантности</option>
                                <option value="new">Сначала новые</option>
                                <option value="price_asc">Сначала дешевые</option>
                                <option value="price_desc">Сначала дорогие</option>
                            </select>
                            <button className="save-button filter-button" type="submit">
                                Искать
                            </button>
                        </div>
                    </form>
                </div>

                <div className="search-body">
                    <aside className="search-facets">
                        <div className="search-facets-header">
                            <h2>Фильтры</h2>
                            {hasActiveFilters ? (
                                <button
                                    type="button"
                                    className="facet-clear-button"
                                    onClick={clearAllFilters}
                                >
                                    Сбросить все
                                </button>
                            ) : null}
                        </div>

                        <section className="facet-group">
                            <h3>Категории</h3>
                            {(facets.categories || []).map((cat) => (
                                <label key={cat.id} className="facet-option">
                                    <input
                                        type="checkbox"
                                        checked={selectedCategoryIds.includes(String(cat.id))}
                                        onChange={() => toggleCategory(cat.id)}
                                    />
                                    <span>{cat.name}</span>
                                    <span className="facet-count">{cat.count}</span>
                                </label>
                            ))}
                        </section>

                        <section className="facet-group">
                            <h3>Цена</h3>
                            {(facets.price_ranges || []).map((range) => (
                                <label key={range.key} className="facet-option">
                                    <input
                                        type="checkbox"
                                        checked={selectedPriceRange === range.key}
                                        onChange={() => applyPriceRange(range)}
                                    />
                                    <span>{range.label}</span>
                                    <span className="facet-count">{range.count}</span>
                                </label>
                            ))}
                        </section>

                        <section className="facet-group">
                            <h3>Наличие</h3>
                            {(facets.availability || []).map((item) => (
                                <label key={String(item.value)} className="facet-option">
                                    <input
                                        type="checkbox"
                                        checked={selectedInStock === (item.value ? '1' : '0')}
                                        onChange={() => toggleInStock(item.value)}
                                    />
                                    <span>{item.value ? 'В наличии' : 'Нет в наличии'}</span>
                                    <span className="facet-count">{item.count}</span>
                                </label>
                            ))}
                        </section>

                        {Object.keys(facets.attributes || {}).length > 0 ? (
                            <div className="facet-attributes">
                                <h3 className="facet-attributes-title">Атрибуты</h3>
                                {Object.entries(facets.attributes || {}).map(([name, values]) => {
                                    const selectedCount = (selectedAttrs[name] || []).length;
                                    return (
                                        <details
                                            key={name}
                                            className="facet-group facet-group--collapsible"
                                        >
                                            <summary className="facet-group-summary">
                                                <span>{name}</span>
                                                {selectedCount > 0 ? (
                                                    <span className="facet-selected-count">{selectedCount}</span>
                                                ) : null}
                                            </summary>
                                            <div className="facet-group-body">
                                                {values.map((item) => (
                                                    <label
                                                        key={`${name}-${item.value}`}
                                                        className="facet-option"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={(selectedAttrs[name] || []).includes(
                                                                item.value
                                                            )}
                                                            onChange={() => toggleAttr(name, item.value)}
                                                        />
                                                        <span>{item.value}</span>
                                                        <span className="facet-count">{item.count}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        </details>
                                    );
                                })}
                            </div>
                        ) : null}
                    </aside>

                    <div className="search-results">
                        <div className="search-results-meta">
                            Найдено: {pagination.total}
                        </div>

                        {loading ? (
                            <div className="status-message">Поиск...</div>
                        ) : (
                            <>
                                <div className="products-grid">
                                    {products.length === 0 ? (
                                        <div className="status-message error" style={{ gridColumn: '1 / -1' }}>
                                            По вашему запросу товары не найдены.
                                        </div>
                                    ) : null}

                                    {products.map((product) => (
                                        <Link
                                            to={`/products/${product.id}`}
                                            key={product.id}
                                            className="product-card"
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
                                                {product.in_stock ? (
                                                    <div className="product-stock in">В наличии</div>
                                                ) : (
                                                    <div className="product-stock out">Нет в наличии</div>
                                                )}
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
            </div>
        </div>
    );
}
