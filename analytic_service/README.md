# Analytic Service

Go-микросервис аналитики: **принимает доменные события из Kafka**, пишет их в **ClickHouse**, и отдаёт **read-only HTTP API** с агрегатами для админ-панели (пользователи, воронка заказов, поиск, разрезы по магазинам/категориям).

Внешний доступ идёт через `auth_service` (JWT + роли `admin` / `analyst`). Сам analytic API внутри Docker-сети аутентификацию не проверяет.

## Архитектура

```mermaid
flowchart LR
  subgraph producers [Kafka]
    UE[user.events]
    OE[order.events]
    SE[search.events]
    PE[product.created_updated]
  end
  subgraph consumersBin [analytic-consumers]
    H[handlers]
    B[microbatch]
    D[DLQ publisher]
  end
  subgraph ch [ClickHouse]
    UR[user_registrations]
    OF[order_funnel_events]
    OLI[order_line_items]
    PD[product_dim]
    SQ[search_queries]
  end
  subgraph apiBin [analytic-api]
    HTTP["GET /analytics/*"]
  end
  DLQ[".dlq topics"]
  Gateway[auth_service gateway]
  UE --> H
  OE --> H
  SE --> H
  PE --> H
  H --> B
  H --> D
  B --> UR
  B --> OF
  B --> OLI
  B --> PD
  B --> SQ
  D --> DLQ
  Gateway --> HTTP
  HTTP --> ch
```

Два бинарника из одного образа (`Dockerfile`):

| Бинарник | Entry | Роль |
|----------|-------|------|
| `analytic-api` | `cmd/api` | HTTP на `:8006` |
| `analytic-consumers` | `cmd/consumers` | Kafka consumer groups → ClickHouse |

Общий старт (config → EnsureDatabase → Connect → Migrate → `store.New`) вынесен в `internal/bootstrap.OpenStore`.

### Пакеты

| Пакет | Назначение |
|-------|------------|
| `internal/config` | Env-конфиг (`CLICKHOUSE_*`, Kafka, HTTP, батчинг, DLQ) |
| `internal/bootstrap` | Общий bootstrap API и consumers |
| `internal/clickhouse` | Connect, create DB, версионированные SQL-миграции (`schema_migrations`) |
| `internal/store` | Батчевые inserts + метрики (ClickHouse), `decimal` для денег |
| `internal/httpapi` | HTTP handlers; зависит от интерфейса `MetricsReader`; безопасные `500` |
| `internal/kafka` | Consumers; единый write-порт `EventStore`, микробатчи, DLQ |
| `internal/testutil` | Хелперы для integration-тестов |

## Compose-сервисы

Из [`compose.yml`](compose.yml) (подключается корневым `compose.yml`):

- `analytic_clickhouse` — ClickHouse 24.8, HTTP `:8123` на хост, native `9000` только во внутренней сети
- `analytic_api` — контейнер `analytic_service_api`, expose `8006`
- `analytic_consumers` — контейнер `analytic_service_consumers`

Сеть: `php_project_net`. Зависимости: ClickHouse healthy; consumers ещё ждут healthy Kafka.

## Конфигурация

См. [`.env.example`](.env.example).

| Переменная | По умолчанию | Описание |
|------------|--------------|----------|
| `HTTP_ADDR` | `:8006` | Listen address API |
| `KAFKA_BROKERS` | `kafka:29092` | CSV брокеров |
| `CLICKHOUSE_ADDR` | `analytic_clickhouse:9000` | Native protocol |
| `CLICKHOUSE_DATABASE` | `analytics` | БД |
| `CLICKHOUSE_USER` | `default` | Пользователь |
| `CLICKHOUSE_PASSWORD` | **обязателен** | Пустой пароль → ошибка `config.Load` |
| `CLICKHOUSE_ASYNC_INSERT` | `1` | Server-side буфер INSERT |
| `CLICKHOUSE_WAIT_FOR_ASYNC_INSERT` | `1` | `1` = ack только после flush на диск; `0` = fire-and-forget (небезопасно) |
| `CLICKHOUSE_ASYNC_INSERT_BUSY_TIMEOUT_MS` | `1000` | Flush по времени (мс) |
| `CLICKHOUSE_ASYNC_INSERT_MAX_DATA_SIZE` | `10485760` | Flush по размеру (байты, 10 МБ) |
| `CLICKHOUSE_ASYNC_INSERT_MAX_QUERY_NUMBER` | `100` | Flush примерно после N INSERT’ов |
| `CLICKHOUSE_BATCH_MAX_ROWS` | `500` | Микробатч: строк на таблицу до flush |
| `CLICKHOUSE_BATCH_FLUSH_INTERVAL_MS` | `1000` | Микробатч: flush по таймеру (мс) |
| `CLICKHOUSE_QUERY_TIMEOUT_MS` | `5000` | Таймаут на каждый запрос к ClickHouse |
| `KAFKA_DLQ_SUFFIX` | `.dlq` | Суффикс DLQ-топика (напр. `user.events.dlq`) |
| `KAFKA_RETRY_BACKOFF_MS` | `1000` | Backoff при retry транзиентной ошибки |
| `MIGRATIONS_DIR` | `migrations` | Каталог `.sql` миграций |

Локальный compose подставляет `${CLICKHOUSE_PASSWORD:-analytic_local_secret}` в ClickHouse, API и consumers.

### Микробатчи и async insert

Consumers копят строки по таблицам и пишут их одним `PrepareBatch` INSERT’ом, когда набрано `CLICKHOUSE_BATCH_MAX_ROWS` строк или истёк `CLICKHOUSE_BATCH_FLUSH_INTERVAL_MS`. Kafka offset коммитится **только после успешной записи** батча (или после отправки сообщения в DLQ), поэтому падение ClickHouse до flush не теряет данные. Серверный `async_insert` дополнительно склеивает батчи, а `wait_for_async_insert=1` гарантирует, что сервер подтверждает запись лишь после flush на диск.

### Смена пароля на уже созданном volume

Официальный образ ClickHouse применяет `CLICKHOUSE_PASSWORD` при **первой** инициализации data directory. Если volume уже создан без пароля (или со старым), пересоздайте:

```bash
docker compose down
docker volume rm php-project_analytic_chdata   # имя может отличаться — проверьте docker volume ls
docker compose up -d analytic_clickhouse analytic_api analytic_consumers
```

После recreate volume ClickHouse пустой, а **Kafka consumer groups сохраняют offsets**. Если consumers поднялись в «залипшем» состоянии (в группе, но `#PARTITIONS=0` — ничего не пишется в CH), перезапустите их:

```bash
docker compose restart analytic_consumers
```

Проверка: `kafka-consumer-groups.sh --describe --group analytic-service-search-v3` должен показать topic/partition и `LAG`. Новые события после успешного assignment снова попадут в ClickHouse; для полного replay истории нужен reset offsets на earliest (или новый group id).

## Kafka: топики и consumer groups

| Group ID | Topics | Обрабатываемые события | Таблица(ы) |
|----------|--------|------------------------|-----------|
| `analytic-service-users-v3` | `user.events` | `user.registered` | `user_registrations` |
| `analytic-service-orders-v5` | `order.events` | `order.created`, `order.paid`, `order.shipped`, `order.cancelled` | `order_funnel_events`, `order_line_items` (только на `created`) |
| `analytic-service-search-v3` | `search.events` | `search.performed` | `search_queries` |
| `analytic-service-products-v1` | `product.events` | `product.created`, `product.updated` | `product_dim` |

Семантика доставки:

- `StartOffset: FirstOffset`, ручной commit — **только после успешного flush батча** в ClickHouse.
- Транзиентная ошибка (сеть / ClickHouse) — retry той же message с backoff (`KAFKA_RETRY_BACKOFF_MS`), следующая не fetch’ится.
- Постоянная ошибка (битый JSON, нет обязательного id) — сообщение уходит в DLQ-топик `<topic><KAFKA_DLQ_SUFFIX>` (напр. `user.events.dlq`) с заголовками `dlq-reason` / `dlq-failed-at`, после чего offset коммитится, чтобы не блокировать партицию.
- Неизвестный тип события и `skip-analytics` — нормальный пропуск без DLQ.
- Тип события: header `event-type`, иначе поля body `event_type` / `event`.
- `order.shipped` в ClickHouse пишется как `event_type = completed` (стадия воронки).

На `order.created` дополнительно пишутся line items; недостающие store/category имена подтягиваются из `product_dim` (`LookupProductDim`). Деньги (`total_amount`, `unit_price`) хранятся и агрегируются через `shopspring/decimal`.

## ClickHouse: схема

Миграции в [`migrations/`](migrations/) применяются один раз и версионируются таблицей `schema_migrations` (имя файла + `applied_at`); повторный старт API/consumers уже применённые файлы пропускает. **Каждый файл содержит ровно один SQL-стейтмент** (ClickHouse отклоняет multi-statements, а SQL-сплиттера в коде нет), поэтому одна миграция = один файл. Тела миграций остаются idempotent (`CREATE` / `ALTER ... IF NOT EXISTS`), поэтому первый запуск поверх существующего volume безопасен.

Движок: **ReplacingMergeTree**. Чтение метрик идёт с **`FINAL`**.

| Таблица | ORDER BY | Содержимое |
|--------|----------|------------|
| `user_registrations` | `(user_id)` | Регистрации (email, name, …) |
| `order_funnel_events` | `(order_id, event_type)` | Стадии: created / paid / completed / cancelled |
| `order_line_items` | `(order_id, product_id, store_id)` | Позиции заказа + store/category |
| `product_dim` | `(product_id)` | Справочник product → store/category |
| `search_queries` | `(kafka_topic, kafka_partition, kafka_offset)` | Поисковые запросы |

### Воронка заказов

Когорта: заказы с `event_type = 'created'` в диапазоне дат.

Исходы по заказу (после агрегации всех событий):

- **cancelled** побеждает **paid** (если оба были — считается cancelled);
- **open** — ни paid, ни cancelled;
- **completed** считается отдельно (`has_completed`, из `order.shipped`).

Конверсии: `paid/created`, `cancelled/created`. Выручка и AOV — по событиям `paid` в диапазоне.

## HTTP API

Базовый URL внутри сети: `http://analytic_service_api:8006`.  
Снаружи (через gateway): `GET /api/analytics/...` на auth_service (прокси на этот сервис).

Общие query-параметры дат:

- `from`, `to` — опционально;
- формат `YYYY-MM-DD` (UTC) или RFC3339;
- для date-only `to` конец **исключительный** (дата + 24h).

| Method | Path | Параметры | Ответ |
|--------|------|-----------|--------|
| `GET` | `/healthz` | — | `{status}` (+ error при CH ping fail) |
| `GET` | `/analytics/users` | `from`, `to` | `total_users`, `growth[]` |
| `GET` | `/analytics/orders` | `from`, `to` | funnel, conversions, revenue, AOV |
| `GET` | `/analytics/orders/stores` | `from`, `to`, `store_id?`, `limit` (1–100, def 20) | ranking или detail + options |
| `GET` | `/analytics/orders/categories` | `from`, `to`, `category_id?`, `limit` | то же для категорий |
| `GET` | `/analytics/search` | `from`, `to`, `limit` | объём, zero-result rate, top queries |

## Локальный запуск

Из корня монорепо:

```bash
# поднять стек (включая analytic_*)
docker compose up -d analytic_clickhouse analytic_api analytic_consumers

# ClickHouse Play (нужен пароль пользователя)
# http://localhost:8123/play  или  http://clickhouse.localhost/play
```

Сборка образа локально:

```bash
docker compose build analytic_api
```

Нативная разработка (нужны доступные Kafka + CH):

```bash
cd analytic_service
cp .env.example .env   # задайте CLICKHOUSE_PASSWORD
export $(grep -v '^#' .env | xargs)
go run ./cmd/api
go run ./cmd/consumers
```

## Lint

Нужны host Go 1.27.0 и [golangci-lint](https://golangci-lint.run/) v2.15.x. Конфиг: [`.golangci.yml`](.golangci.yml).

```bash
# из корня репозитория
./scripts/lint-all.sh analytic
./scripts/lint-all.sh --fix analytic   # gofmt/goimports и другие автофиксы

# или напрямую
cd analytic_service
golangci-lint run ./...
golangci-lint run --fix ./...
```

В CI (`deploy.yml` job lint) analytic прогоняется вместе с Laravel через `./scripts/lint-all.sh --no-fix`.

## Тесты

```bash
cd analytic_service
go test ./...

# integration (нужен ClickHouse на native-порту)
# из корня: docker compose -f compose.yml -f analytic_service/compose.test.yml up -d analytic_clickhouse
CLICKHOUSE_ADDR=localhost:19000 \
CLICKHOUSE_PASSWORD=analytic_local_secret \
CLICKHOUSE_DATABASE=analytics_test \
go test -tags=integration ./internal/kafka/...
```

Корневой [`scripts/test-all.sh`](../scripts/test-all.sh) гоняет analytic-тесты в Docker-сети с тем же паролем.

## Trust model (кратко)

- Публичный путь: JWT + роли в `auth_service`.
- East-west: любой контейнер в `php_project_net` может дернуть `:8006` напрямую.
- ClickHouse HTTP на хосте защищён паролем пользователя; не оставляйте пустой `CLICKHOUSE_PASSWORD`.
