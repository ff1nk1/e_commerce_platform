# Notification service

Laravel-сервис **писем и журнала отправок**. Слушает Kafka и шлёт почту; админка читает лог и может переотправить письмо. Порт `:8003`, снаружи только через gateway (`admin` и `analyst`).

Общая карта вызовов — в [корневом README](../README.md).

## Данные

| Хранилище | Что лежит |
|-----------|-----------|
| MongoDB `email_logs` | статус, payload, retries, признак DLQ |
| Postgres | служебные таблицы Laravel |
| Mailhog | локальный SMTP (`:1025`) |

## Kafka

Два консьюмера из [`compose.yml`](compose.yml). Тип события — header `event-type`. Неизвестные типы пропускаются. Ошибка доставки уходит в DLQ, запись в `email_logs` помечается `moved_to_dlq`.

| Команда | Топик | События | Письмо |
|---------|-------|---------|--------|
| `kafka:consume:user-events` | `user.events` | `user.registered` | [`WelcomeMail`](app/Mail/WelcomeMail.php) |
| `kafka:consume:order-events` | `order.events` | `order.paid`, `order.confirmed`, `order.cancelled` | соответствующие Mailable |

Остальные события `order.events` (`order.created`, `order.shipped`, stock saga) сервис не обрабатывает. DLQ: `user.events.dlq`, `order.events.dlq`.

Пайплайн — [`KafkaMailPipeline`](app/Services/KafkaMailPipeline.php), соответствие тип → класс письма — [`MailableRegistry`](app/Mail/MailableRegistry.php).

## HTTP

Префикс Laravel `/api`. На самом сервисе auth middleware нет: доступ режет gateway.

| Метод | Путь | Назначение |
|-------|------|------------|
| GET | `/notifications` | список писем |
| GET | `/notifications/{id}` | карточка |
| POST | `/notifications/{id}/resend` | повторная отправка |

Маршруты — [`routes/api.php`](routes/api.php).
