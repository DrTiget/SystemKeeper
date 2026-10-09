# SystemKeeper

Сервис бекапов и мониторинга: собирает бекапы с серверов и БД по SSH, хранит локально и дублирует на несколько аккаунтов Яндекс.Диска; uptime-мониторинг с инцидентами и алертами.

- Laravel 13, PHP 8.5, MySQL, очереди `database`, Docker
- API-only (`/api/v1`), web-часть пустая — API переиспользуется клиентами

## Фронтенд

SPA в каталоге `frontend/`: Vue 3, TypeScript, Vite, vue-router, pinia, Tailwind CSS v4. Дизайн — по `docs/design-system/` (токены подключены в `frontend/src/styles/app.css`).

Запуск (сервис `frontend` в `compose.yaml`, dev-сервер Vite):

```sh
docker compose up -d frontend   # npm install и vite на порту FRONTEND_PORT (по умолчанию 5173)
```

Откройте `http://localhost:${FRONTEND_PORT:-5173}`. Запросы `/api` проксируются на `http://nginx:80`. Токен хранится в `localStorage`; без токена или при `401` от API происходит переход на `/login`. Проверка типов: `docker compose exec frontend npm run typecheck`; сборка: `docker compose exec frontend npm run build`.

## API: авторизация

Bearer-токены (Laravel Sanctum), без cookie/SPA-сессий. Все ответы — JSON, базовый путь `/api/v1`. Публичной регистрации нет. CORS включён для `/api/*`.

Создать первого пользователя:

```sh
docker compose exec app php artisan user:create
# или: --name="Админ" --email=admin@example.com --password=secret123
```

Недостающие поля команда спросит интерактивно (пароль скрыто); email уникален, пароль — минимум 8 символов.

| Метод и путь | Auth | Тело | Ответ |
|---|---|---|---|
| `POST /api/v1/auth/login` | — | `{email, password, device_name?}` | `200 {token, user}`; `422` при неверных данных; `429` после 5 неудачных попыток в минуту на email+IP |
| `POST /api/v1/auth/logout` | Bearer | — | `204` (текущий токен удалён) |
| `GET /api/v1/auth/me` | Bearer | — | `200 {data: {id, name, email, created_at}}` |

Токен передаётся заголовком `Authorization: Bearer <token>`. Без токена или с отозванным — `401 {"message": "Unauthenticated."}`. Ошибки валидации — `422 {"message", "errors": {поле: [..]}}`.

## API: серверы и SSH-ключи

Все маршруты под `/api/v1`, требуют `Authorization: Bearer <token>` (без токена — `401`). Списки пагинируются: `?page=N&per_page=1..100` (по умолчанию 15), ответ `{data: [...], links, meta}`. Одиночные ресурсы — `{data: {...}}`. Ошибки валидации — `422 {message, errors}`. Секреты (`private_key`, `passphrase`, `password`) в ответах отсутствуют никогда; в БД хранятся зашифрованными (`APP_KEY`).

### SSH-ключи

Ресурс: `{id, name, type: "ed25519"|"rsa", public_key, fingerprint: "SHA256:…", has_passphrase, servers_count (в index/show), created_at, updated_at}`.

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /ssh-keys` | — | `200` страница ключей |
| `POST /ssh-keys` | генерация: `{name, type?: "ed25519"(по умолчанию)\|"rsa"}` (rsa — 4096 бит); импорт: `{name, private_key, passphrase?}` | `201`; `422` — имя занято, ключ нечитаем/не ed25519/rsa, нужна или неверна парольная фраза (ошибка в `private_key`) |
| `GET /ssh-keys/{id}` | — | `200` |
| `PUT/PATCH /ssh-keys/{id}` | `{name}` (остальное игнорируется) | `200`; `422` |
| `DELETE /ssh-keys/{id}` | — | `204`; `409`, если ключ используется серверами |
| `GET /ssh-keys/{id}/public` | — | `200 {data: {public_key, fingerprint}}` — строка в формате `authorized_keys` |

### Серверы

Ресурс: `{id, name, host, port, username, auth_type: "key"|"password", ssh_key_id, ssh_key: {id, name, type, fingerprint}|null, has_password, description, last_checked_at, last_check_status: "ok"|"failed"|null, last_check_error, host_fingerprint, created_at, updated_at}`.

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /servers` | — | `200` страница |
| `POST /servers` | `{name, host, port? (1–65535, по умолчанию 22), username, auth_type, ssh_key_id (обязателен при key), password (обязателен при password), description?}` | `201`; `422` |
| `GET /servers/{id}` | — | `200` |
| `PUT/PATCH /servers/{id}` | любые поля из создания; при смене `auth_type` обязательны соответствующие `ssh_key_id`/`password`; лишние учётные данные обнуляются; смена `host`/`port` сбрасывает `host_fingerprint` | `200`; `422` |
| `DELETE /servers/{id}` | — | `204` |
| `POST /servers/{id}/check` | — | `200 {data: {status: "ok"\|"failed", error, output: {exit_code, stdout, stderr}\|null, checked_at, server}}` |
| `POST /servers/{id}/reset-fingerprint` | — | `200` сервер с `host_fingerprint: null` |

Проверка выполняет `uname -a && uptime` (таймауты — `config/ssh.php`, env `SSH_CONNECT_TIMEOUT`, `SSH_EXEC_TIMEOUT`). Сбой подключения — тоже `200` со `status: "failed"` и текстом в `error`; результат сохраняется в `last_check_*`. Ненулевой код выхода — `failed`.

Отпечаток хоста (TOFU): при первом успешном подключении `host_fingerprint` (SHA256 host key) запоминается; если позже он не совпал, подключение отклоняется с ошибкой «Отпечаток хоста изменился». Принять новый ключ хоста — `POST /servers/{id}/reset-fingerprint`, затем повторить проверку.
