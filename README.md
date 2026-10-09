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

Ресурс: `{id, name, host, port, username, auth_type: "key"|"password", ssh_key_id, ssh_key: {id, name, type, fingerprint}|null, has_password, description, last_checked_at, last_check_status: "ok"|"failed"|null, last_check_error, host_fingerprint, metrics_settings, metrics_collected_at, metrics_last_error, created_at, updated_at}` (`metrics_settings` — см. «Метрики серверов»).

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

## API: метрики серверов

Раз в N минут планировщик собирает с серверов нагрузку, память и диски по SSH — одной командой через `SshConnectorInterface::exec`:
`cat /proc/loadavg /proc/meminfo`, `nproc`, `/proc/uptime`, `df -PTk` (на BusyBox без `-T` — запасной `df -Pk`) и `df -Pi` (inodes). Разбор рассчитан на Debian/Ubuntu/CentOS (в т.ч. старые ядра без `MemAvailable`) и Alpine/BusyBox; псевдо-ФС (`tmpfs`, `devtmpfs`, `squashfs`…), `/proc`, `/sys`, `/dev`, `/run`, `/snap` и docker-монтирования `/etc/hosts|hostname|resolv.conf` отбрасываются, дубли одной ФС схлопываются. Занятая память = `MemTotal − MemAvailable` (без кэша); занятость диска считается как «Use%» в `df`: `used / (used + avail)`.

### Настройки (в ресурсе сервера и в `POST/PUT /servers`)

```json
"metrics_settings": {
  "enabled": true,
  "interval_minutes": 5,
  "confirmations": 2,
  "thresholds": { "disk_percent": 90, "load_per_core": 2.0, "ram_percent": 90 }
}
```

В запросе можно передавать любое подмножество ключей (остальное не меняется). `interval_minutes` 1–1440; `thresholds.*: null` выключает проверку; `disk_percent`/`ram_percent` 1–100, `load_per_core` 0.1–100 — это `load average за 5 минут / число ядер`; `confirmations` 1–10 — антидребезг (см. ниже). Значения по умолчанию — как в примере. Результат последнего сбора: `metrics_collected_at`, `metrics_last_error` (null, если всё хорошо).

### Эндпоинты

| Метод и путь | Ответ |
|---|---|
| `GET /servers/{id}/metrics/latest` | `200 {data: {metric, alerts, collected_at, last_error}}` — последний замер (`null`, если ещё не было) и активные превышения порогов |
| `GET /servers/{id}/metrics?from=&to=&points=` | `200 {data: {from, to, bucket_seconds, points: [...]}}` — история для графиков. `from`/`to` — даты ISO 8601 (по умолчанию последние сутки; глубже срока хранения не отдаётся), `points` 2–1000 (по умолчанию 200) — максимум точек: период делится на равные окна (не короче минуты), значения в окне усредняются. `422`, если `from ≥ to` |
| `POST /servers/{id}/metrics/collect` | `200 {data: {status: "ok"\|"failed", error, metric}}` — собрать прямо сейчас (синхронно, пороги тоже проверяются) |

Замер (`metric`): `{id, server_id, collected_at, load: {load1, load5, load15, per_core}, cpu_count, memory: {total_bytes, used_bytes, available_bytes, used_percent}, swap: {total_bytes, used_bytes, used_percent|null}, uptime_seconds, disks: [{mount, device, fstype|null, total_bytes, used_bytes, avail_bytes, used_percent, inodes_total, inodes_used, inodes_free}]}`.

Точка истории: `{time, samples, load1|load5|load15|load_per_core|mem_used_percent|swap_used_percent: {avg, max}, mem_used_bytes, disks: [{mount, total_bytes, used_bytes, used_percent: {avg, max}}]}`. Окна без замеров в ответ не попадают (на графике — разрыв).

Активное превышение (`alerts[]`): `{metric: "disk"|"load"|"ram", target: точка монтирования|null, value, threshold, exceeded_since}`.

### Пороги и события

После каждого замера значения сравниваются с порогами (срабатывание — `значение ≥ порога`; диски проверяются по каждой точке монтирования отдельно). Антидребезг: событие `App\Events\MetricThresholdExceeded` отправляется, когда превышение удержалось `confirmations` замеров подряд (один всплеск не в счёт), и больше не повторяется, пока показатель не вернётся в норму; `App\Events\MetricThresholdRecovered` — когда в норме `confirmations` замеров подряд. Если порог выключили или точка монтирования исчезла, открытое превышение закрывается событием возврата. Поля событий: `server`, `sample` (замер), `metric` (`disk|load|ram`), `target`, `value`, `threshold` (проценты или нагрузка на ядро), у `Recovered` ещё `exceededSince`. События только диспатчатся — отправку алертов делает отдельный модуль. Сбой сбора (SSH недоступен и т. п.) событий не порождает — он виден в `metrics_last_error`.

### Планировщик

`metrics:dispatch` (каждую минуту) ставит Job `CollectServerMetrics` для серверов с включённым сбором, срок которых подошёл (`metrics_next_at`; смена настроек сбрасывает его). `metrics:prune` (ежедневно в 03:45) удаляет замеры старше `METRICS_RETENTION_DAYS` (по умолчанию 30) дней; также `METRICS_EXEC_TIMEOUT` (30 с) и `METRICS_DISPATCH_LIMIT` (200) — см. `config/metrics.php`. Для серверов, добавленных до этой функции, сбор включается автоматически с настройками по умолчанию — отключить можно `metrics_settings.enabled: false`.

## API: хранилища

Куда складываются бекапы. Типы: `local` (каталог на сервере SystemKeeper), `yandex_disk` (аккаунт Яндекс.Диска; аккаунтов может быть несколько), `s3` (AWS S3 и S3-совместимые: Yandex Object Storage, MinIO и т. п.), `sftp` («другой сервер» — запись из «Серверов», файлы кладутся по SSH).

Ресурс (поля, не относящиеся к типу, приходят `null`): `{id, name, type: "local"|"yandex_disk"|"s3"|"sftp", enabled, base_path, has_token, has_secret, server_id, server: {id, name, host, port} (sftp), endpoint, region, bucket, prefix, access_key, path_style, storage_class (s3), last_checked_at, last_check_status: "ok"|"failed"|null, last_check_error, created_at, updated_at}`. Секреты (OAuth-токен ЯД, `secret_key` S3) хранятся зашифрованно и **никогда не отдаются в API** — только флаги `has_token` / `has_secret`. Для s3 `prefix` — это `base_path` без ведущего «/» (`""` — корень бакета).

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /storage-targets` | — | `200` страница |
| `POST /storage-targets` | `{name, type, enabled? (true)}` + поля типа (ниже) | `201`; `422` (сообщения на русском, по полям) |
| `GET /storage-targets/{id}` | — | `200` |
| `PUT/PATCH /storage-targets/{id}` | поля типа из создания (любые); `type` игнорируется (не меняется); пустой или отсутствующий секрет сохраняет прежний | `200`; `422` |
| `DELETE /storage-targets/{id}` | — | `204`; записи о копиях удаляются, файлы в хранилище остаются |
| `POST /storage-targets/{id}/check` | — | `200 {data: {status: "ok"\|"failed", error, total_space, used_space, free_space}}` (байты, `null`, если хранилище место не сообщает). Сбой — тоже `200` со `status: "failed"`; результат сохраняется в `last_check_*` |

Поля по типам (обязательные при создании):

| Тип | Поля |
|---|---|
| `local` | `base_path` — абсолютный путь без «..» |
| `yandex_disk` | `base_path`, `token` (OAuth-токен) |
| `s3` | `region`, `bucket`, `access_key`, `secret_key`; необязательные: `endpoint` (URL, для S3-совместимых; пусто — AWS), `prefix` (папка внутри бакета), `path_style` (bool, нужен MinIO и многим совместимым), `storage_class` (например `STANDARD_IA`, у Yandex — `COLD`) |
| `sftp` | `server_id` (существующий сервер: те же SSH-ключ/пароль и отпечаток хоста), `base_path` — абсолютный путь на нём |

Что делает `check`: `yandex_disk` — проверяет токен и возвращает квоту; `local` — каталог и право записи, место на диске; `s3` — `HeadBucket` плюс запись и удаление маленького тестового объекта (место не сообщается); `sftp` — подключение, запись и удаление тестового файла в `base_path`, место (`df`). Сервер, на который ссылается хранилище `sftp`, удалить нельзя: `DELETE /servers/{id}` вернёт `409`.

### Загрузка файлов (для модуля бекапов)

```php
use App\Services\Storage\StorageUploaderInterface;

$uploader = app(StorageUploaderInterface::class);
$uploader->dispatch($localPath, $targets, 'server-1/2026/dump.sql.gz', $backup); // в очередь (Job UploadFileToStorageTargets)
$copies = $uploader->upload($localPath, $targets, 'server-1/dump.sql.gz', $backup); // синхронно, список StorageCopy
```

- `$targets` — модели `StorageTarget` (или их id для `dispatch`); отключённые (`enabled=false`) Job пропускает. Удалённый путь — `base_path` цели + `remotePath`.
- `$backup` — необязательная модель-владелец (полиморфная связь `uploadable`); жёсткой привязки к `Backup` нет.
- Учёт копий — таблица `storage_copies` (модель `StorageCopy`): цель, `remote_path`, `status` (`pending|uploading|uploaded|failed`), `size`, `md5`, `sha256`, `error`, `attempts`, `uploaded_at`. Заполняются только подтверждённые хранилищем суммы: ЯД и S3 — `md5`, sftp — `sha256`, local — `md5`.
- Сбой одной цели не мешает остальным. Если хоть одна копия упала, Job завершается ошибкой и перезапускается очередью (3 попытки, паузы 60 и 300 с); уже загруженные копии пропускаются.
- Яндекс.Диск: файл отправляется потоково (в память не читается), недостающие папки создаются рекурсивно, после загрузки md5 сверяется с метаданными ресурса; запросы с 429/5xx и сетевыми сбоями повторяются с экспоненциальным backoff. Настройки — `config/yandex_disk.php` (`YANDEX_DISK_ATTEMPTS`, `YANDEX_DISK_BACKOFF_MS`, `YANDEX_DISK_TIMEOUT`, `YANDEX_DISK_UPLOAD_TIMEOUT`, `YANDEX_DISK_VERIFY_ATTEMPTS`).

- S3: загрузка потоком; файлы от `S3_MULTIPART_THRESHOLD` (16 МиБ) уходят multipart (часть `S3_PART_SIZE`, 16 МиБ, при больших файлах растёт автоматически), в памяти держатся лишь несколько частей. Целостность: размер объекта и ETag сверяются с вычисленными локально (для multipart — составной `md5(md5 частей)-N`); при несовпадении объект удаляется, копия — `failed`. Если ETag нестандартный (шифрование SSE-KMS), проверяется размер. Недогруженный multipart при сбое отменяется (`AbortMultipartUpload`). Ретраи с backoff — в SDK (`S3_ATTEMPTS`, 5). Настройки — `config/storage_targets.php`.
- sftp: системный `ssh` читает файл прямо с диска и подаёт в stdin `cat > файл.part` на удалённом сервере; затем там считается `sha256sum` (или `shasum -a 256`), и только при совпадении `.part` переименовывается в итоговый файл (иначе удаляется). Проверка отпечатка хоста (TOFU) — как у остальных SSH-операций сервера. При сбое соединения загрузка повторяется (`SFTP_ATTEMPTS`, 3, с паузой `SFTP_BACKOFF_MS`); таймауты — `SFTP_UPLOAD_TIMEOUT`, `SFTP_COMMAND_TIMEOUT`. Требует на удалённой стороне POSIX-оболочку и `sha256sum`/`shasum`.
- Ротация и удаление бекапов удаляют копии во всех типах хранилищ (`deleteCopy`): S3 — `DeleteObject` (только внутри префикса), sftp — `rm -f` (только внутри `base_path`).

### Как получить OAuth-токен Яндекс.Диска

1. На <https://oauth.yandex.ru/client/new> создайте приложение: платформа «Веб-сервисы», Callback URI — `https://oauth.yandex.ru/verification_code`, доступы — `cloud_api:disk.read`, `cloud_api:disk.write`, `cloud_api:disk.info` (для папки приложения — `cloud_api:disk.app_folder`).
2. Откройте `https://oauth.yandex.ru/authorize?response_type=token&client_id=<ID приложения>` под нужным аккаунтом и разрешите доступ; токен будет в адресе страницы после `access_token=`.
3. Передайте его в `token` при создании хранилища и проверьте через `POST /storage-targets/{id}/check`. Для каждого аккаунта ЯД — отдельное хранилище.

## API: дашборд

`GET /api/v1/dashboard` (Bearer-токен) — сводка одним запросом, на каждый блок фиксированное число SQL-запросов (без N+1). Необязательный параметр `stale_days` (1–365, по умолчанию 2) — порог для «заданий без свежего бекапа». Ответ `{data: {...}}`:

| Блок | Поля |
|---|---|
| `servers` | `total`, `check_ok`, `check_failed`, `unchecked` — по последней проверке подключения |
| `backups` | `success_24h`, `failed_24h`, `success_7d`, `failed_7d` (по времени завершения; `pending`/`running` не считаются); `last_failed` — 5 последних неудачных `[{id, backup_job_id, job_name, error (до 300 симв.), finished_at}]`; `upcoming` — 5 ближайших запусков по cron `[{backup_job_id, job_name, next_run_at}]`; `local_size_bytes` — объём успешных бекапов на локальном диске; `size_by_storage` — загруженные копии по хранилищам `[{storage_target_id, name, type, copies, size_bytes}]`; `stale_days`; `stale_jobs` — включённые задания, у которых последний успешный бекап старше `stale_days` (или его нет, а задание создано раньше) `[{backup_job_id, job_name, last_success_at|null}]` |
| `monitoring` | `total`, `up`, `down`, `paused`, `pending` (включены, ещё не проверялись), `active_incidents_count`, `active_incidents` (до 10, новые первыми) `[{id, monitor_id, monitor_name, reason, started_at}]`, `uptime_24h`, `uptime_30d` — среднее по мониторам с проверками за окно (`null`, если проверок нет) |
| `storages` | `[{id, name, type, enabled, last_checked_at, last_check_status, total_space, free_space}]` — место по результату последнего `POST /storage-targets/{id}/check` (байты; `null`, если не проверялось или хранилище место не сообщает, например S3) |

Фронт (`/`) показывает эти данные плитками и списками со ссылками в разделы и обновляет их раз в 30 секунд.

## API: мониторинг

Маршруты под `/api/v1` требуют `Authorization: Bearer <token>`, кроме публичного heartbeat. Пагинация как в остальных списках (`?page`, `?per_page`).

### Мониторы

Типы и цель (`target`):

| `type` | `target` | Проверка |
|---|---|---|
| `http` | URL `http(s)://…` | ответ с ожидаемым кодом `expected_status` (без него — любой 2xx) |
| `keyword` | URL | как `http` + в теле есть `keyword` (обязателен) |
| `tcp` | `host:port` | TCP-соединение открывается |
| `ping` | хост или IP | ICMP echo (нужен `ping` в контейнере — `iputils-ping`) |
| `ssl` | `host`, `host:port` или `https://…` | сертификат валиден и до истечения осталось не меньше `ssl_expiry_days` дней |
| `heartbeat` | — (не задаётся) | пинг на `heartbeat_url` был не позже чем `interval_seconds` + `heartbeat_grace_seconds` назад |

Ресурс: `{id, name, type, target, interval_seconds, timeout_seconds, expected_status, keyword, ssl_expiry_days, heartbeat_token, heartbeat_url, heartbeat_grace_seconds, last_heartbeat_at, failure_threshold, enabled, status: "up"|"down"|null, last_response_time_ms|null, last_error|null (результат последней проверки), consecutive_failures, last_checked_at, next_check_at, open_incident|null (в index/show), uptime (в index и show), created_at, updated_at}`.

| Метод и путь | Тело / параметры | Ответ |
|---|---|---|
| `GET /monitors` | `?type=`, `?status=up\|down`, `?enabled=0\|1` | `200` страница; каждый монитор сразу с `uptime` и `open_incident` (фиксированное число запросов, без N+1) |
| `POST /monitors` | `{name, type, target, interval_seconds? (30–86400, по умолчанию 60), timeout_seconds? (1–60, 10), expected_status? (100–599), keyword, ssl_expiry_days? (1–365, 14), heartbeat_grace_seconds? (60), failure_threshold? (1–100, 3), enabled? (true)}` | `201`; `422`; для `heartbeat` токен генерируется |
| `GET /monitors/{id}` | — | `200` с `uptime` |
| `PUT/PATCH /monitors/{id}` | любые поля создания, кроме `type` (менять нельзя) | `200`; `422` |
| `DELETE /monitors/{id}` | — | `204` (проверки и инциденты удаляются) |
| `POST /monitors/{id}/pause` · `/resume` | — | `200`; при возобновлении сбрасываются счётчик неудач и отсчёт heartbeat |
| `GET /monitors/{id}/checks` | `?status=up\|down` | `200` страница `{id, monitor_id, status, response_time_ms, status_code, error, checked_at}`, новые первыми |
| `GET /monitors/{id}/incidents` | `?status=open\|resolved` | `200` страница инцидентов |
| `GET /monitors/{id}/uptime` | — | `200 {data: {"24h"\|"7d"\|"30d": {percent, checks, failed}}}` |

Uptime — доля успешных проверок за окно; `percent: null`, если проверок не было.

### Инциденты

Ресурс: `{id, monitor_id, monitor: {id, name, type} (в общем списке и show), status: "open"|"resolved", started_at, resolved_at, reason, duration_seconds}` (у открытого `duration_seconds` — на текущий момент).

`GET /incidents` (`?monitor_id=`, `?status=open|resolved`) и `GET /incidents/{id}`. Порядок в `/incidents`: сначала активные (`resolved_at` пуст), затем по `started_at` убыванию — сортировка на уровне запроса, поэтому корректна между страницами.

Антидребезг: инцидент открывается, когда монитор не прошёл проверку `failure_threshold` раз подряд, и закрывается при первой успешной проверке. На открытие и закрытие бросаются события `App\Events\IncidentOpened` и `App\Events\IncidentResolved` (свойство `incident`) — на них будут подписаны алерты.

### Heartbeat

`GET` или `POST /api/v1/heartbeat/{token}` — **без авторизации**, токен берётся из `heartbeat_url` монитора. `200 {data: {ok: true, paused: false}}`; неизвестный токен — `404`; монитор на паузе — `200` с `paused: true`, пинг не учитывается. Пример для cron: `curl -fsS https://host/api/v1/heartbeat/<token>`. Пинг закрывает открытый инцидент сразу, без ожидания планировщика.

### Планировщик

`monitors:dispatch` (каждую минуту, `schedule:work` в compose) ставит в очередь Job `RunMonitorCheck` для включённых мониторов, у которых `next_check_at` подошёл (или не задан); воркер `queue` выполняет проверки. `monitors:prune` (ежедневно) удаляет результаты старше `MONITOR_CHECK_RETENTION_DAYS` (по умолчанию 35) дней. Для включения `ping` в уже собранном контейнере пересоберите образ (`docker compose up -d --build`).

## API: бекапы

Все маршруты под `/api/v1`, `Authorization: Bearer <token>`. Списки пагинируются (`?page=N&per_page=1..100`), ответы — `{data: ...}`. Секреты (`db_password`) в ответах отсутствуют, в БД хранятся зашифрованными.

### Как выполняется бекап

Бекап — задание очереди `database` (`RunBackup`, без повторов). Данные стримятся по SSH прямо в локальный файл `storage/app/backups/{server_id}/{job_id}/{YYYYmmdd-HHmmss}-{backup_id}.{tar.zst|sql.zst}` — на сервере ничего не пишется на диск. Размер, `sha256` и `md5` считаются на лету.

| Тип | Команда (на сервере по SSH, либо локально в режиме `direct`) |
|---|---|
| `files` | `tar -C / -cf - [--exclude=…] -- <пути> \| zstd -T0 -N -q -c` |
| `mysql` | `mysqldump --single-transaction --quick --routines --triggers --events --no-tablespaces [--host --port --user] <база>\|--all-databases \| zstd …` |
| `postgres` | `pg_dump --no-password … --dbname=<база>` (без `db_name` — `pg_dumpall`) `\| zstd …` |

Конвейер запускается под `bash -o pipefail`: падение `tar`/`mysqldump` не маскируется успешным `zstd`. Пароль БД передаётся переменной окружения (`MYSQL_PWD`/`PGPASSWORD`), а не аргументом, и вырезается из `error`/`log`. Ненулевой код выхода или пустой вывод — `failed`, недописанный файл удаляется. На сервере должны быть `zstd`, `tar` (для БД — `mysqldump`/`pg_dump`); режим `direct` требует их в контейнере `app` (в `docker/php/Dockerfile` добавлены `zstd` и `postgresql-client`). Пересоберите образ: `docker compose up -d --build`.

Планировщик: команда `php artisan backups:run-due` (раз в минуту через `schedule:work`) ставит в очередь задания, у которых по cron наступило время после `last_scheduled_at` (или создания). Пропущенные запуски не копятся, при выполняющемся бекапе запуск пропускается. Зависшие `pending`/`running` старше `BACKUP_TIMEOUT` (по умолчанию 14400 с) + 10 минут помечаются `failed`. Для приёма очереди нужен воркер (`queue`), `DB_QUEUE_RETRY_AFTER` по умолчанию 15000 с.

### SSH-драйвер потока

Данные тянет драйвер, выбираемый переменной `SSH_STREAM_DRIVER`:

- `openssh` (по умолчанию) — системный клиент `ssh` (`SSH_BINARY`, по умолчанию `ssh`; есть в образе `app`). На тестовом sshd в Docker: **≈100 МБ/с** против ≈5 МБ/с у phpseclib (400 МБ: 4 с против 74 с), память PHP ровная (~28 МБ).
- `phpseclib` — чистый PHP, ничего не требует от окружения, но медленный.

Проверка `check` и прочие короткие команды всегда идут через phpseclib. Семантика TOFU у драйвера `openssh` та же: отпечаток хоста сверяется с `host_fingerprint` до запуска `ssh` (изменился — «Отпечаток хоста изменился», `ssh` не запускается); `ssh` стартует с `StrictHostKeyChecking=yes` и `known_hosts` из единственного — только что проверенного — ключа хоста; при первом подключении отпечаток запоминается после успешной авторизации. Закрытый ключ (без парольной фразы) и `known_hosts` лежат во временном каталоге `0700`, файл ключа — `0600`, всё удаляется сразу после команды, в том числе при ошибке и таймауте. Пароль серверов с парольной авторизацией передаётся через `SSH_ASKPASS` (переменной окружения процесса), а не аргументом. Код выхода `255` — ошибка самого ssh (авторизация, соединение): бекап `failed` с текстом ошибки.

### Задания (`backup-jobs`)

Ресурс: `{id, name, server_id, server: {id, name}, type: "files"|"mysql"|"postgres", paths, excludes, db_mode: "ssh"|"direct"|null, db_host, db_port, db_name, db_user, has_db_password, db_ssl_verify, compression_level (1–19, по умолчанию 3), schedule (cron, 5 полей)|null, enabled, next_run_at, last_scheduled_at, retention_count, retention_days, keep_daily, keep_weekly, keep_monthly, storage_target_ids, backups_count, latest_backup, created_at, updated_at}`.

`storage_target_ids` — id хранилищ (см. «API: хранилища»), куда выгружается каждый успешный бекап (должны существовать; отключённые хранилища пропускаются). Выгрузка идёт Job-ом `UploadFileToStorageTargets` на удалённый путь `{server_id}/{job_id}/{файл}` внутри `base_path` хранилища. Неуспех выгрузки не делает бекап неуспешным: статус каждой копии виден в `copies` бекапа. Ротация — см. «Ротация бекапов» ниже.

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /backup-jobs` | фильтры `server_id`, `type`, `enabled` | `200` страница |
| `POST /backup-jobs` | общие: `name`, `server_id`, `type`, `schedule?`, `enabled?` (true), `compression_level?`, `retention_count?`, `retention_days?`, `storage_target_ids?`. `files`: `paths` (абсолютные, обязательно), `excludes?`. `mysql`/`postgres`: `db_mode?` (`ssh` по умолчанию; `direct` — SystemKeeper сам подключается к БД и требует `db_host`, `db_user`), `db_host?`, `db_port?`, `db_name?` (пусто — все базы), `db_user?`, `db_password?`, `db_ssl_verify?` (по умолчанию true; false — не проверять TLS-сертификат MySQL в режиме `direct`, нужно для самоподписанных) | `201`; `422` |
| `GET /backup-jobs/{id}` | — | `200` |
| `PUT/PATCH /backup-jobs/{id}` | любые поля создания, кроме `type` (не меняется); поля чужого типа обнуляются | `200`; `422` |
| `DELETE /backup-jobs/{id}` | `?keep_remote=1` (не удалять копии в хранилищах) | `204` (вместе с бекапами, файлами и копиями в хранилищах); `409`, если есть выполняющийся бекап или идёт выгрузка; `502` — копию удалить не удалось |
| `POST /backup-jobs/{id}/rotate` | `{dry_run?: bool}` | `200 {data: {dry_run, kept, deleted: [id бекапов], errors: [..]}}` — применяет правила ротации сразу; с `dry_run` только показывает, что будет удалено |
| `POST /backup-jobs/{id}/run` | — | `202 {data: backup}` со `status: "pending"`; `409`, если бекап уже выполняется или в очереди |

### Ротация бекапов

Поля задания (все необязательные, `null` — правило не используется): `retention_count` (хранить N последних успешных), `retention_days` (хранить успешные не старше N суток), схема GFS — `keep_daily`, `keep_weekly`, `keep_monthly` (оставить по одному, самому свежему, бекапу за каждый из последних N дней / недель ISO / месяцев, **в которых бекапы были**). Правила складываются по «ИЛИ»: бекап хранится, если его защищает хотя бы одно. Нет ни одного правила — ничего не удаляется.

- Самый свежий успешный бекап не удаляется никогда; выполняющиеся (`pending`/`running`) не затрагиваются.
- Неуспешные бекапы старше `backup.failed_retention_days` (7 суток, `config/backup.php`) удаляются, если у задания есть хоть одно правило.
- Удаляется всё: копии в хранилищах (`deleteCopy`: Яндекс.Диск — `DELETE /resources`, local — `unlink` внутри `base_path`, S3 — `DeleteObject`, sftp — `rm -f` на сервере), локальный файл и запись. Если копию в хранилище удалить не удалось, бекап остаётся целиком, ошибка попадает в `errors`, остальные удаляются; повтор — при следующем запуске.
- Когда выполняется: сразу после успешного бекапа задания; ежедневно в 04:15 — команда `php artisan backups:rotate [--job=ID] [--dry-run]` (в планировщике; с `--dry-run` только печатает, что будет удалено); по запросу — `POST /backup-jobs/{id}/rotate`.

Удаление сервера удаляет его задания, бекапы и локальные файлы, но не копии в хранилищах (их записи удаляются, файлы остаются).

### Бекапы (`backups`)

Ресурс: `{id, backup_job_id, backup_job: {id, name, type, server_id}, status: "pending"|"running"|"success"|"failed", trigger: "manual"|"schedule", has_file, file_name, size_bytes, sha256, md5, started_at, finished_at, duration_seconds, error, log (только в show — stderr команды), copies (в index и show), created_at, updated_at}`. Путь к файлу на диске не отдаётся. Копия в `copies`: `{id, storage_target_id, storage_target: {id, name, type}|null, remote_path, status: "pending"|"uploading"|"uploaded"|"failed", size, md5, sha256, error, attempts, uploaded_at}`; `pending`-копии появляются сразу после бекапа.

| Метод и путь | Ответ |
|---|---|
| `GET /backups` | `200` страница, новые первыми; фильтры `backup_job_id`, `server_id`, `status` |
| `GET /backups/{id}` | `200` с `log` |
| `GET /backups/{id}/download` | `200` файл (`application/zstd`, заголовок `X-Checksum-Sha256`); `404`, если бекап не `success` или файл отсутствует |
| `POST /backups/{id}/download-link` | `200 {data: {url, expires_at}}` — временная подписанная ссылка на файл (срок `BACKUP_DOWNLOAD_LINK_MINUTES`, по умолчанию 5 мин); `404`, если бекап не `success` или файла нет |
| `GET /backups/{id}/file?expires=…&signature=…` | по ссылке из `download-link`, **без `Authorization`**: `200`/`206` файл. `403` — срок истёк, подпись или параметры изменены; `404` — файла уже нет |
| `DELETE /backups/{id}` | `204`: удаляются локальный файл, запись и копии в хранилищах; `?keep_remote=1` — копии в хранилищах не трогать. `409`, если бекап `pending`/`running` или его выгрузка ещё идёт; `502`, если копию в хранилище удалить не удалось (бекап остаётся целиком, можно повторить) |

**Скачивание ссылкой.** `GET /backups/{id}/file` отдаёт файл потоком: `Content-Length`, `Accept-Ranges: bytes` и `Range` (докачка, ответ `206`), `X-Checksum-Sha256`, `Content-Disposition: attachment`. Подписывается путь с query без хоста, поэтому `url` можно открывать через любой адрес сервиса (за прокси, подменяющим `Host`, берите из `url` только путь и query). Одна ссылка открывает только свой бекап. Фронт скачивает именно так — обычным переходом, без загрузки файла в память браузера. `GET /backups/{id}/download` с Bearer-токеном остаётся для скриптов (тоже с `Range`).
