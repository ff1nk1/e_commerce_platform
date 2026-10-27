# search_service

Порт `8005`. Индекс и DSL — в [README сервиса](../../search_service/README.md).

## Назначение

Поиск и autocomplete по каталогу. Своей SQL-базы нет: документы живут в OpenSearch и приезжают из Kafka.

## Зависимости

| Что | Зачем |
|-----|--------|
| OpenSearch | индексы `products` и `categories` |
| Kafka | product, category, `catalog.events`; публикация `search.events` |

## ENV

| Переменная | Тип | Дефолт | Обязательна |
|------------|-----|--------|-------------|
| `KAFKA_BROKERS` | string | `kafka:29092` | да |
| `OPENSEARCH_HOSTS` | URL | `http://search_opensearch:9200` | да |
| `OPENSEARCH_PRODUCTS_INDEX` | string | `products` | нет |
| `OPENSEARCH_CATEGORIES_INDEX` | string | `categories` | нет |
| `OPENSEARCH_TRACK_TOTAL_HITS` | int | `10000` | нет |
| `OPENSEARCH_REFRESH_ON_WRITE` | bool | `false` | нет |
| `SEARCH_THROTTLE_PER_MINUTE` | int | `60` | нет |
| `ALLOW_SKIP_ANALYTICS` | bool | `false` | нет. `true` только для нагрузочных прогонов |

## Запуск и тесты

```bash
./scripts/test-all.sh search
./scripts/lint-all.sh search
```

Нагрузочный сценарий: [`search_service/k6`](../../search_service/k6).
