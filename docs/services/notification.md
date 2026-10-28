# notification_service

Порт `8003`. Письма и журнал — в [README сервиса](../../notification_service/README.md).

## Назначение

Письма по событиям Kafka и журнал отправок для админки.

## Зависимости

| Что | Зачем |
|-----|--------|
| SQLite | пустое подключение Laravel, очереди не используются |
| MongoDB `email_logs` | статусы писем |
| Kafka | `user.events`, `order.events`, свои DLQ |
| Mailpit | SMTP на `1025` |

Другие сервисы по HTTP не вызывает. Снаружи его читает gateway.

## ENV

| Переменная | Тип | Дефолт | Обязательна |
|------------|-----|--------|-------------|
| `DB_CONNECTION` | string | `sqlite` | нет |
| `DB_DATABASE` | string | `database/database.sqlite` | нет |
| `MONGODB_HOST` | string | `notification_service_mongodb` | да |
| `MONGODB_PORT` | int | `27017` | да |
| `MONGODB_DATABASE` | string | `notification_db` | да |
| `MONGO_INITDB_ROOT_USERNAME` / `PASSWORD` | string | `root` / `root` | да, для локального Mongo |
| `KAFKA_BROKERS` | string | `kafka:29092` | да |
| `KAFKA_TOPIC_USER_EVENTS` | string | `user.events` | нет |
| `KAFKA_TOPIC_USER_EVENTS_DLQ` | string | `user.events.dlq` | нет |
| `KAFKA_TOPIC_ORDER_EVENTS` | string | `order.events` | нет |
| `KAFKA_TOPIC_ORDER_EVENTS_DLQ` | string | `order.events.dlq` | нет |
| `MAIL_HOST` | string | `notification_service_mailpit` | да |
| `MAIL_PORT` | int | `1025` | да |
| `MAIL_FROM_ADDRESS` | string | `noreply@example.com` | нет |

## Запуск и тесты

```bash
./scripts/test-all.sh notification
./scripts/lint-all.sh notification
```

Письма: [http://localhost:8025](http://localhost:8025).
