# order_service

Порт `8004`. Saga и Stripe — в [README сервиса](../../order_service/README.md).

## Назначение

Заказы из снимков товаров, резерв остатка, оплата Stripe, отмена и отгрузка.

## Зависимости

| Что | Зачем |
|-----|--------|
| Postgres `order_db` | заказы, платежи, снимки, outbox |
| Redis | идемпотентность create/pay, шаги saga |
| Kafka | чтение product и stock; публикация `order.events` |
| Stripe | Checkout и вебхуки |
| catalog | только через Kafka, не HTTP |

## ENV

| Переменная | Тип | Дефолт | Обязательна |
|------------|-----|--------|-------------|
| `DB_HOST` | string | `order_service_db` | да |
| `DB_DATABASE` | string | `order_db` | да |
| `DB_USERNAME` / `DB_PASSWORD` | string | `laravel` / `secret` | да |
| `REDIS_HOST` | string | `order_service_redis` | да |
| `KAFKA_BROKERS` | string | `kafka:29092` | да |
| `KAFKA_CONSUMER_GROUP_ID` | string | `order-service-products-v1` | нет |
| `CATALOG_DB_*` | string | база catalog | только для сидера снимков |
| `STRIPE_SECRET` | string | пусто | да, для оплаты и integration-тестов |
| `STRIPE_WEBHOOK_SECRET` | string | пусто | да, для вебхуков и тех же тестов |
| `STRIPE_SUCCESS_URL` | URL | `https://app.localhost/orders/{ORDER_ID}?paid=1` | нет |
| `STRIPE_CANCEL_URL` | URL | `https://app.localhost/orders/{ORDER_ID}?canceled=1` | нет |

## Запуск и тесты

```bash
./scripts/test-all.sh order
./scripts/lint-all.sh order
```

`test-all.sh order` гоняет и unit, и integration. Integration падает, пока в `order_service/.env` нет тестовых ключей Stripe.
