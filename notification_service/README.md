# Notification service

Laravel-сервис **писем и журнала отправок**. Слушает Kafka и шлёт почту; админка читает лог и может переотправить письмо. Порт `:8003`, снаружи только через gateway (`admin` и `analyst`).

Общая карта вызовов — в [корневом README](../README.md).

## Данные

| Хранилище | Что лежит |
|-----------|-----------|
| MongoDB `email_logs` | статус, payload, retries, признак DLQ (модель `Notification`) |
| Postgres `notification_db` | Ecotone outbox/inbox |
| Mailpit | локальный SMTP (`:1025`), UI `:8025` |

## Kafka

Два консьюмера из [`compose.yml`](compose.yml). Тип события — header `event-type`. Неизвестные типы пропускаются. Ошибка доставки уходит в DLQ, запись в `email_logs` помечается `moved_to_dlq`.

| Команда | Топик | События | Письмо |
|---------|-------|---------|--------|
| `kafka:consume:user-events` | `user.events` | `user.registered` | [`WelcomeMail`](app/Mail/WelcomeMail.php) |
| `kafka:consume:order-events` | `order.events` | `order.paid`, `order.confirmed`, `order.cancelled` | соответствующие Mailable |

Остальные события `order.events` (`order.created`, `order.shipped`, stock saga) сервис не обрабатывает. DLQ: `user.events.dlq`, `order.events.dlq`.

Маршрутизация события в письмо — [`NotificationEventsHandler`](app/Kafka/Handlers/NotificationEventsHandler.php) (тонкий адаптер) + [`SendNotificationService`](app/Services/Notifications/SendNotificationService.php) (создание/повтор записи, ретраи через [`RetryingMailSender`](app/Services/Notifications/RetryingMailSender.php)). Соответствие тип → Mailable задаёт [`NotificationEventType::mailable()`](app/Enums/NotificationEventType.php), набор разрешённых событий на консьюмер — VO [`AllowedEventTypes`](app/ValueObjects/AllowedEventTypes.php). Ручная переотправка — [`ResendNotificationService`](app/Services/Notifications/ResendNotificationService.php) (только `failed`, иначе `InvalidArgumentException` → 422).

## HTTP

Префикс Laravel `/api`. На самом сервисе auth middleware нет: доступ режет gateway.

| Метод | Путь | Назначение |
|-------|------|------------|
| GET | `/notifications` | список писем |
| GET | `/notifications/{id}` | карточка |
| POST | `/notifications/{id}/resend` | повторная отправка |

Маршруты — [`routes/api.php`](routes/api.php).
