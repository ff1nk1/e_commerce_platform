# catalog_service

Порт `8002`. Домен, saga остатков и кэш — в [README сервиса](../../catalog_service/README.md).

## Назначение

Товары, категории, магазины, остатки, загрузка картинок и Filament-админка. Источник правды каталога для остальных сервисов.

## Зависимости

| Что | Зачем |
|-----|--------|
| Postgres `catalog_db` | товары, категории, остатки, outbox |
| Redis | кэш витрины, шаги saga |
| MinIO | картинки |
| Kafka | product/category сразу, stock через outbox; чтение `order.events` |

От HTTP других сервисов не зависит. Сам его вызывает recommendation.

## ENV

| Переменная | Тип | Дефолт | Обязательна |
|------------|-----|--------|-------------|
| `DB_HOST` | string | `catalog_service_db` | да |
| `DB_DATABASE` | string | `catalog_db` | да |
| `DB_USERNAME` / `DB_PASSWORD` | string | `laravel` / `secret` | да |
| `REDIS_HOST` | string | `catalog_service_redis` | да |
| `SESSION_PATH` | string | `/admin-catalog` | да, для Filament |
| `KAFKA_BROKERS` | string | `kafka:29092` | да |
| `KAFKA_CONSUMER_GROUP_ID` | string | `catalog-service-orders-v1` | нет |
| `AWS_ACCESS_KEY_ID` | string | `minioadmin` | да, для загрузки |
| `AWS_SECRET_ACCESS_KEY` | string | `minioadminpassword` | да, для загрузки |
| `AWS_BUCKET` | string | `media` | да |
| `AWS_ENDPOINT` | URL | `http://catalog-minio:9000` | да |
| `AWS_USE_PATH_STYLE_ENDPOINT` | bool | `true` | да |
| `MEDIA_PUBLIC_BASE` | string | `/storage` | нет |

## Запуск и тесты

```bash
./scripts/test-all.sh catalog
./scripts/lint-all.sh catalog
```
