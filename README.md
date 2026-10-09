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

## API: хранилища

Куда складываются бекапы: локальный каталог (`local`) или аккаунт Яндекс.Диска (`yandex_disk`); аккаунтов ЯД может быть несколько.

Ресурс: `{id, name, type: "local"|"yandex_disk", base_path, enabled, has_token, last_checked_at, last_check_status: "ok"|"failed"|null, last_check_error, created_at, updated_at}`. OAuth-токен хранится зашифрованно и **никогда не отдаётся в API** — только флаг `has_token`.

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /storage-targets` | — | `200` страница |
| `POST /storage-targets` | `{name, type, token (обязателен для yandex_disk), base_path (абсолютный, без «..»), enabled? (по умолчанию true)}` | `201`; `422` |
| `GET /storage-targets/{id}` | — | `200` |
| `PUT/PATCH /storage-targets/{id}` | `{name?, token?, base_path?, enabled?}`; тип не меняется; без `token` (или с пустым) прежний токен сохраняется | `200`; `422` |
| `DELETE /storage-targets/{id}` | — | `204`; записи о копиях удаляются, файлы в хранилище остаются |
| `POST /storage-targets/{id}/check` | — | `200 {data: {status: "ok"\|"failed", error, total_space, used_space, free_space}}` (байты); для ЯД проверяются токен и свободное место, для local — каталог и права записи. Сбой — тоже `200` со `status: "failed"`; результат сохраняется в `last_check_*` |

### Загрузка файлов (для модуля бекапов)

```php
use App\Services\Storage\StorageUploaderInterface;

$uploader = app(StorageUploaderInterface::class);
$uploader->dispatch($localPath, $targets, 'server-1/2026/dump.sql.gz', $backup); // в очередь (Job UploadFileToStorageTargets)
$copies = $uploader->upload($localPath, $targets, 'server-1/dump.sql.gz', $backup); // синхронно, список StorageCopy
```

- `$targets` — модели `StorageTarget` (или их id для `dispatch`); отключённые (`enabled=false`) Job пропускает. Удалённый путь — `base_path` цели + `remotePath`.
- `$backup` — необязательная модель-владелец (полиморфная связь `uploadable`); жёсткой привязки к `Backup` нет.
- Учёт копий — таблица `storage_copies` (модель `StorageCopy`): цель, `remote_path`, `status` (`pending|uploading|uploaded|failed`), `size`, `md5`, `error`, `attempts`, `uploaded_at`.
- Сбой одной цели не мешает остальным. Если хоть одна копия упала, Job завершается ошибкой и перезапускается очередью (3 попытки, паузы 60 и 300 с); уже загруженные копии пропускаются.
- Яндекс.Диск: файл отправляется потоково (в память не читается), недостающие папки создаются рекурсивно, после загрузки md5 сверяется с метаданными ресурса; запросы с 429/5xx и сетевыми сбоями повторяются с экспоненциальным backoff. Настройки — `config/yandex_disk.php` (`YANDEX_DISK_ATTEMPTS`, `YANDEX_DISK_BACKOFF_MS`, `YANDEX_DISK_TIMEOUT`, `YANDEX_DISK_UPLOAD_TIMEOUT`, `YANDEX_DISK_VERIFY_ATTEMPTS`).

### Как получить OAuth-токен Яндекс.Диска

1. На <https://oauth.yandex.ru/client/new> создайте приложение: платформа «Веб-сервисы», Callback URI — `https://oauth.yandex.ru/verification_code`, доступы — `cloud_api:disk.read`, `cloud_api:disk.write`, `cloud_api:disk.info` (для папки приложения — `cloud_api:disk.app_folder`).
2. Откройте `https://oauth.yandex.ru/authorize?response_type=token&client_id=<ID приложения>` под нужным аккаунтом и разрешите доступ; токен будет в адресе страницы после `access_token=`.
3. Передайте его в `token` при создании хранилища и проверьте через `POST /storage-targets/{id}/check`. Для каждого аккаунта ЯД — отдельное хранилище.
