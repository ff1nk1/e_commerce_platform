# analytic_service

Порт `8006`. Схема ClickHouse и эндпоинты — в [README сервиса](../../analytic_service/README.md).

## Назначение

Read-only агрегаты для админки: регистрации, воронка заказов, поиск. Пишет в ClickHouse из Kafka. Сам JWT не проверяет.

Два процесса из одного образа: `analytic-api` и `analytic-consumers`.

## Зависимости

| Что | Зачем |
|-----|--------|
| ClickHouse `analytics` | таблицы метрик |
| Kafka | `user.events`, `order.events`, `search.events`, `product.created`, `product.updated` |

## ENV

| Переменная | Тип | Дефолт | Обязательна |
|------------|-----|--------|-------------|
| `HTTP_ADDR` | string | `:8006` | нет |
| `KAFKA_BROKERS` | string | `kafka:29092` | да |
| `CLICKHOUSE_ADDR` | string | `analytic_clickhouse:9000` | да |
| `CLICKHOUSE_DATABASE` | string | `analytics` | да |
| `CLICKHOUSE_USER` | string | `default` | да |
| `CLICKHOUSE_PASSWORD` | string | `analytic_local_secret` | да. Пустой пароль `config.Load` отвергает |
| `CLICKHOUSE_ASYNC_INSERT` | 0/1 | `1` | нет |
| `CLICKHOUSE_WAIT_FOR_ASYNC_INSERT` | 0/1 | `0` | нет |
| `CLICKHOUSE_ASYNC_INSERT_BUSY_TIMEOUT_MS` | int | `1000` | нет |
| `CLICKHOUSE_ASYNC_INSERT_MAX_DATA_SIZE` | int, байты | `10485760` | нет |
| `CLICKHOUSE_ASYNC_INSERT_MAX_QUERY_NUMBER` | int | `100` | нет |
| `MIGRATIONS_DIR` | string | `migrations` | нет |

## Запуск и тесты

```bash
./scripts/test-all.sh analytic
./scripts/lint-all.sh analytic
```

Линт на хосте: Go 1.23+ и golangci-lint v1.64. `test-all.sh` гоняет `go test` в Docker-сети с ClickHouse.
