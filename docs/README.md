# Документация

С чего начать, если проект видишь первый раз.

| Документ | О чём |
|----------|--------|
| [Архитектура](architecture.md) | Gateway, Kafka, базы, внешние системы |
| [Инфраструктура](infrastructure.md) | Логи, Mailpit, локальный запуск, CI |
| [ADR](adr/) | Почему gateway, Kafka, отдельные хранилища, Stripe |
| [Сервисы](services/) | Назначение, зависимости, ENV, тесты каждого сервиса |

Swagger UI (стек должен быть запущен): [https://app.localhost/docs/](https://app.localhost/docs/). Спека — [`auth_service/public/docs/openapi.yaml`](../auth_service/public/docs/openapi.yaml).

Запуск всего стека — в [корневом README](../README.md).
