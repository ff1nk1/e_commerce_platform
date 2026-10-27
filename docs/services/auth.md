# auth_service

Порт `8001`. Подробности HTTP и Kafka — в [README сервиса](../../auth_service/README.md).

## Назначение

Регистрация, логин, роли и API gateway. Единственная проверка JWT. Проксирует `/api` в остальные сервисы.

## Зависимости

| Что | Зачем |
|-----|--------|
| Postgres `auth_db` | users, roles |
| Redis | blacklist access-токенов, refresh |
| Kafka | публикация `user.registered` |
| catalog, notification, order, search, analytic, recommendation | цели прокси |

Консьюмеров Kafka нет.

## ENV

Стандартных ключей Laravel в таблице нет. Полный список — [`.env.example`](../../auth_service/.env.example).

| Переменная | Тип | Дефолт | Обязательна |
|------------|-----|--------|-------------|
| `DB_HOST` | string | `auth_service_db` | да |
| `DB_DATABASE` | string | `auth_db` | да |
| `DB_USERNAME` / `DB_PASSWORD` | string | `laravel` / `secret` | да |
| `REDIS_HOST` | string | `auth_service_redis` | да |
| `JWT_SECRET` | string | пусто | да, для логина |
| `JWT_TTL` | int, минуты | `15` | нет |
| `JWT_REFRESH_TTL` | int, секунды | `2592000` | нет |
| `JWT_ALGO` | string | `HS256` | нет |
| `JWT_COOKIE_DOMAIN` | string | пусто | нет |
| `JWT_COOKIE_SECURE` | bool | `true` | нет |
| `CATALOG_SERVICE_URL` | URL | `http://catalog_service_app:8002` | да |
| `NOTIFICATION_SERVICE_URL` | URL | `http://notification_service_app:8003` | да |
| `ORDER_SERVICE_URL` | URL | `http://order_service_app:8004` | да |
| `SEARCH_SERVICE_URL` | URL | `http://search_service_app:8005` | да |
| `ANALYTIC_SERVICE_URL` | URL | `http://analytic_service_api:8006` | да |
| `RECOMMENDATION_SERVICE_URL` | URL | `http://recommendation_service_app:8007` | да |
| `KAFKA_BROKERS` | string | `kafka:29092` | да, чтобы публиковать регистрацию |

## Запуск и тесты

Стек поднимается из корня: `docker compose up -d --build`.

```bash
./scripts/test-all.sh auth
./scripts/lint-all.sh auth
```
