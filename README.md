# SystemKeeper

Сервис бекапов и мониторинга. Сейчас проект пересобирается с нуля: оставлены только пользователи и авторизация (прежние модули — в истории git до коммита с их удалением).

- Laravel 13, PHP 8.5, MySQL, очереди `database`, Docker
- API-only (`/api/v1`): сайт и будущее мобильное приложение работают с одними и теми же методами API, отдельных «веб-only» эндпоинтов нет

## Фронтенд

SPA в каталоге `frontend/`: Vue 3, TypeScript, Vite, vue-router, pinia, Tailwind CSS v4. Дизайн — по `docs/design-system/` (токены подключены в `frontend/src/styles/app.css`).

Запуск (сервис `frontend` в `compose.yaml`, dev-сервер Vite):

```sh
docker compose up -d frontend   # npm install и vite на порту FRONTEND_PORT (по умолчанию 5173)
```

Откройте `http://localhost:${FRONTEND_PORT:-5173}`. Запросы `/api` проксируются на `http://nginx:80`. Токен хранится в `localStorage`; без токена или при `401` от API происходит переход на `/login`. Проверка типов: `docker compose exec frontend npm run typecheck`; сборка: `docker compose exec frontend npm run build`.

## Документация API (OpenAPI)

Описание генерируется из кода пакетом [dedoc/scramble](https://scramble.dedoc.co): интерактивная страница — `/api/docs`, спецификация OpenAPI 3.1 — `/api/docs.json` (`php artisan scramble:export` — выгрузка в файл). В `APP_ENV=local` доступна всем, в остальных окружениях — только с `Authorization: Bearer <token>`. Авторизация в описании — Bearer (Sanctum).

## Ошибки API

Стандартные ошибки возвращаются как `{"message": "…"}` на русском: `401` «Требуется авторизация.», `403` «Доступ запрещён.», `404` «Не найдено.», `405` «Метод не поддерживается.», `429` «Слишком много запросов. Повторите позже.», `500` «Внутренняя ошибка сервера.» (подробности — только в логах). Ошибки валидации `422` — `{"message", "errors": {поле: [..]}}`.

## API: авторизация

Bearer-токены (Laravel Sanctum), без cookie/SPA-сессий. Все ответы — JSON, базовый путь `/api/v1`. Публичной регистрации нет. CORS включён для `/api/*`.

Создать первого пользователя:

```sh
docker compose exec app php artisan user:create
# или: --name="Админ" --email=admin@example.com --password=secret123
```

Команда создаёт администратора; остальных пользователей заводят через API `/users` (раздел «Пользователи» на сайте). Недостающие поля команда спросит интерактивно (пароль скрыто); email уникален, пароль — минимум 8 символов.

| Метод и путь | Auth | Тело | Ответ |
|---|---|---|---|
| `POST /api/v1/auth/login` | — | `{email, password, device_name?}` | `200 {token, user}`; `422` при неверных данных; `429` после 5 неудачных попыток в минуту на email+IP |
| `POST /api/v1/auth/logout` | Bearer | — | `204` (текущий токен удалён) |
| `GET /api/v1/auth/me` | Bearer | — | `200 {data: {id, name, email, is_admin, settings, created_at}}` |

Токен передаётся заголовком `Authorization: Bearer <token>`. Без токена или с отозванным — `401 {"message": "Требуется авторизация."}`. Ошибки валидации — `422 {"message", "errors": {поле: [..]}}`.

## API: профиль

Профиль текущего пользователя — общий для сайта и мобильного приложения. Все методы — с `Authorization: Bearer <token>`.

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /api/v1/profile` | — | `200 {data: {id, name, email, settings, created_at}}` |
| `PATCH /api/v1/profile` (или `PUT`) | любые из `{name, email, current_password, settings: {theme}}` | `200 {data: …}` — обновлённый профиль |
| `PUT /api/v1/profile/password` | `{current_password, password, password_confirmation}` | `204`; все сеансы, кроме текущего, завершаются |
| `GET /api/v1/profile/tokens` | — | `200 {data: [{id, name, current, last_used_at, created_at}]}` — сеансы, новые сверху |
| `DELETE /api/v1/profile/tokens/{id}` | — | `204`; чужой или несуществующий — `404` |
| `DELETE /api/v1/profile/tokens` | — | `204` — завершены все сеансы, кроме текущего |

- Смена `email` требует `current_password`.
- `settings` передаются частично и сливаются с текущими; неизвестные ключи — `422`. Сейчас там одна настройка — `theme`: `light`, `dark` или `system` (по умолчанию).
- Имя сеанса — `device_name` из `POST /auth/login` (сайт передаёт `web`); приложению стоит передавать название устройства.
- `GET /auth/me` оставлен, он возвращает то же, что `GET /profile`.

## API: пользователи

Несколько пользователей; управлять ими может только администратор (`is_admin`), остальным — `403`. Права в остальном одинаковые.

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /api/v1/users?search=&page=&per_page=` | — | `200` — страница пользователей по имени (`per_page` до 100, по умолчанию 25), `search` — по имени и email |
| `POST /api/v1/users` | `{name, email, password, is_admin?}` | `201 {data: …}` |
| `GET /api/v1/users/{id}` | — | `200 {data: …}` |
| `PATCH /api/v1/users/{id}` (или `PUT`) | любые из `{name, email, password, is_admin}` | `200 {data: …}`; при смене пароля сеансы пользователя завершаются |
| `DELETE /api/v1/users/{id}` | — | `204`; удаляются и его сеансы |

Снять права администратора с себя нельзя (`422`), удалить себя — тоже (`409`), поэтому в системе всегда остаётся хотя бы один администратор. Через `PATCH /profile` права администратора не меняются.

## API: серверы

Сервер — хост с SSH-подключением по ключу или по паролю. Доступно всем пользователям.

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /api/v1/servers?search=&page=&per_page=` | — | `200` — страница серверов по названию; `search` — по названию, хосту и описанию |
| `POST /api/v1/servers` | `{name, description?, host, port?, username, auth_type, private_key?, passphrase?, password?}` | `201 {data: …}` |
| `GET /api/v1/servers/{id}` | — | `200 {data: …}` |
| `PATCH /api/v1/servers/{id}` (или `PUT`) | любые поля из `POST` | `200 {data: …}` |
| `DELETE /api/v1/servers/{id}` | — | `204` |
| `POST /api/v1/servers/{id}/check` | — | `200 {data: {status: ok\|failed, error, output, server}}` — выполняет `uname -a && uptime` |
| `POST /api/v1/servers/{id}/reset-fingerprint` | — | `200 {data: …}` — забыть отпечаток хоста |

- `auth_type`: `key` — нужен `private_key` (OpenSSH/PEM/PuTTY, `passphrase`, если ключ зашифрован; ключ проверяется при сохранении), `password` — нужен `password`. `port` по умолчанию 22.
- Ключ, фраза и пароль хранятся в БД зашифрованными (`APP_KEY`) и никогда не отдаются: в ответе только `has_private_key`, `has_passphrase`, `has_password`. При изменении их можно не передавать — останутся прежние; при смене `auth_type` нужны новые, данные другого способа удаляются.
- Отпечаток хоста (`host_fingerprint`, SHA256 как в `ssh-keygen -l`) запоминается при первом успешном подключении; если ключ сервера потом изменится, проверка вернёт `failed` с ошибкой «Отпечаток хоста изменился». Смена `host` или `port` сбрасывает отпечаток.
- Результат последней проверки — в `last_check: {status, error, checked_at}`. Таймауты — `SSH_CONNECT_TIMEOUT` (10 с) и `SSH_EXEC_TIMEOUT` (30 с).

