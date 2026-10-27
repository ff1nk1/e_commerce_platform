# recommendation_service

Порт `8007`. Пайплайн ассистента — в [README сервиса](../../recommendation_service/README.md).

## Назначение

Чат-ассистент: локальный каталог в Postgres с pgvector, фильтры и текст ответа через Gemini.

## Зависимости

| Что | Зачем |
|-----|--------|
| Postgres + pgvector | `product_catalog`, диалоги |
| Kafka | product.* и `stock.changed` |
| catalog HTTP | проверка свежести карточек |
| Gemini | extract, embed, ответ |

## ENV

| Переменная | Тип | Дефолт | Обязательна |
|------------|-----|--------|-------------|
| `DB_HOST` | string | `recommendation_service_db` | да |
| `DB_DATABASE` | string | `recommendation_db` | да |
| `DB_USERNAME` / `DB_PASSWORD` | string | `laravel` / `secret` | да |
| `KAFKA_BROKERS` | string | `kafka:29092` | да |
| `KAFKA_CONSUMER_GROUP_PRODUCTS` | string | `recommendation-service-products-v1` | нет |
| `KAFKA_CONSUMER_GROUP_STOCK` | string | `recommendation-service-stock-v1` | нет |
| `LLM_DRIVER` | string | `gemini` | нет |
| `GEMINI_API_KEY` | string | плейсхолдер | да, для ассистента и Gemini-тестов |
| `GEMINI_CHAT_MODEL` | string | `gemini-3.8-flash` | нет |
| `GEMINI_EMBEDDING_MODEL` | string | `gemini-embedding-001` | нет |
| `GEMINI_EMBEDDING_DIMENSIONS` | int | `768` | нет |
| `GEMINI_TIMEOUT` | int, секунды | `25` | нет |
| `GEMINI_TEMPERATURE` | float | `0.3` | нет |
| `CATALOG_SERVICE_URL` | URL | `http://catalog_service_app:8002` | да, для freshness |
| `RECOMMENDATION_MAX_COSINE_DISTANCE` | float | `0.75` | нет |
| `RECOMMENDATION_REQUIRE_IN_STOCK` | bool | `true` | нет |
| `RECOMMENDATION_CATALOG_TIMEOUT` | int, секунды | `5` | нет |
| `RECOMMENDATION_INTERNAL_TOKEN` | string | пусто | нет. Если задан, API ждёт заголовок `X-Internal-Token` |

## Запуск и тесты

```bash
./scripts/test-all.sh recommendation
./scripts/lint-all.sh recommendation
```

Без `GEMINI_API_KEY` ассистент пустой, тесты Gemini пропускаются. Демо-данные: `./scripts/demo-prep.sh`.
