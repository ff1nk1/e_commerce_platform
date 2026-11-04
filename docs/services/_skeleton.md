# Шаблон слоёв нового сервиса

Скелет каталогов Laravel-сервиса. Новый код кладём по этой раскладке; в уже живущих сервисах двигаем старое при следующем касании файла. Обоснование — [ADR 0006](../adr/0006-app-layers.md).

```text
app/
  Http/
    Controllers/      # тонкие контроллеры, вызов сервиса
    Requests/         # валидация входа
    Middleware/       # периметр: роль из X-User-Role, identity из X-User-Id
    Resources/        # JsonResource: whitelist публичных полей
  Services/           # одношаговые сценарии (use-case)
  Saga/               # долгоживущие сценарии Ecotone #[Saga] + их сообщения/конвертеры
  Kafka/
    Handlers/         # адаптеры входящих Kafka-сообщений
  Messaging/          # DTO/envelope событий, не относящиеся к саге
  Models/             # Eloquent
  DTO/                # неизменяемые структуры входа/выхода
  Enums/              # типизированные перечисления
  Events/ Listeners/  # доменные события Laravel
  ValueObjects/       # Money и подобное
  Support/            # мелкие утилиты слоя
config/
  ecotone.php         # namespaces + document_store
  messaging.php       # список саг для Document Store
  kafka.php           # consumer/dlq/retry
routes/
  api.php
```

Правила:

- Сага — только `App\Saga`. Транспортный обработчик (`Kafka/Handlers`) публикует шаг саги; сага про Kafka не знает.
- Контроллер не содержит бизнес-логики: валидация → `Services`/`Saga` → `Http\Resources`.
- Наружу отдаём только `Http\Resources` — `saga_id`, Stripe-id и служебные поля не покидают сервис.
- Identity читаем из заголовков `X-User-Id` / `X-User-Role`, роль проверяем middleware'ом.
