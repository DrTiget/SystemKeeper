# SystemKeeper API

Все методы — JSON под `/api/v1`. Интерфейс SystemKeeper работает только через этот API, так что всё, что видно в интерфейсе, доступно и скриптам.

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

## API: проекты

Проект — группа серверов и баз данных. Сервер или база входит в один проект (или ни в один): поле `project_id` в `POST/PATCH /servers` и `/databases`, в ответе — `project` (`id`, `name`) или `null`.

| Метод | Путь | Описание |
|---|---|---|
| GET | `/projects` | Проекты по названию с `servers_count` и `databases_count`; `search` |
| POST | `/projects` | Создать: `name`, `description` |
| GET | `/projects/{id}` | Проект |
| PATCH | `/projects/{id}` | Изменить |
| DELETE | `/projects/{id}` | Удалить; серверы и базы остаются без проекта |

Состав проекта — `GET /servers?project_id={id}` и `GET /databases?project_id={id}` (`project_id=none` — без проекта). Бекапы всех серверов и баз проекта — `GET /projects/{id}/backups` (в каждом — `source`: `type`, `id`, `name`).

### Группы проектов

Проект входит в одну группу или ни в одну: поле `group_id` в `POST/PATCH /projects` (в ответе — `group_id`), фильтр `GET /projects?group_id={id}` (`none` — без группы).

| Метод | Путь | Описание |
|---|---|---|
| GET | `/project-groups` | Все группы по названию с `projects_count` |
| POST | `/project-groups` | Создать: `name` |
| GET / PATCH | `/project-groups/{id}` | Группа / переименовать |
| DELETE | `/project-groups/{id}` | Удалить; проекты остаются без группы |

## API: серверы

Сервер — хост с SSH-подключением по ключу или по паролю. Доступно всем пользователям.

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /api/v1/servers?search=&page=&per_page=` | — | `200` — страница серверов по названию; `search` — по названию, хосту и описанию |
| `POST /api/v1/servers` | `{name, description?, host, port?, username, auth_type, generate_key?, key_type?, private_key?, passphrase?, password?}` | `201 {data: …}` |
| `GET /api/v1/servers/{id}` | — | `200 {data: …}` |
| `PATCH /api/v1/servers/{id}` (или `PUT`) | любые поля из `POST` | `200 {data: …}` |
| `DELETE /api/v1/servers/{id}` | — | `204` |
| `POST /api/v1/servers/{id}/check` | — | `200 {data: {status: ok\|failed, error, output, server}}` — выполняет `uname -a && uptime` |
| `POST /api/v1/servers/{id}/reset-fingerprint` | — | `200 {data: …}` — забыть отпечаток хоста |
| `GET /api/v1/servers/{id}/files?path=/var` | — | `200 {data: {path, entries: [{name, path, type: dir\|file\|other, link, size}], truncated}}` — содержимое каталога по SSH; `422` — путь не найден / нет доступа, `502` — нет SSH |
| `GET /api/v1/backup/pre-command-templates` | — | `200 {data: [{key, group, title, description, command}]}` — шаблоны команды перед бекапом |
| `GET /api/v1/servers/{id}/backup-settings` | — | `200 {data: …}` — настройки бекапа (см. ниже) |
| `PUT /api/v1/servers/{id}/backup-settings` (или `PATCH`) | любые из `{enabled, paths, excludes, schedule, pre_command, pre_command_timeout, pre_command_continue_on_error}` | `200 {data: …}` |

- `auth_type`: `key` — нужен `private_key` (OpenSSH/PEM/PuTTY, `passphrase`, если ключ зашифрован; ключ проверяется при сохранении), `password` — нужен `password`. `port` по умолчанию 22.
- Генерация ключа: `auth_type: "key"` и `generate_key: true` (+ `key_type`: `ed25519` по умолчанию или `rsa` — RSA 4096) вместо `private_key` — пара создаётся в сервисе, закрытый ключ остаётся в БД, в ответе `public_key` для `authorized_keys` (комментарий `systemkeeper@<название>`). То же в `PATCH` заменяет текущий ключ. `public_key` заполняется и для вставленного ключа.
- Ключ, фраза и пароль хранятся в БД зашифрованными (`APP_KEY`) и никогда не отдаются: в ответе только `has_private_key`, `has_passphrase`, `has_password`. При изменении их можно не передавать — останутся прежние; при смене `auth_type` нужны новые, данные другого способа удаляются.
- Отпечаток хоста (`host_fingerprint`, SHA256 как в `ssh-keygen -l`) запоминается при первом успешном подключении; если ключ сервера потом изменится, проверка вернёт `failed` с ошибкой «Отпечаток хоста изменился». Смена `host` или `port` сбрасывает отпечаток.
- Результат последней проверки — в `last_check: {status, error, checked_at}`. Таймауты — `SSH_CONNECT_TIMEOUT` (10 с) и `SSH_EXEC_TIMEOUT` (30 с).

### Что бекапить: автопоиск

`POST /servers/{id}/discover` — осмотр сервера по SSH (только чтение, до ~2 минут, не чаще 10 раз в минуту):
`{info: {hostname, os, user, docker}, paths: [{path, category, reasons, inside, recommended, size}], excludes: [{pattern, reason}], databases: [{engine, where: local|docker, container, detail, supported, added}]}`.
`category` — `system`, `apps`, `web`, `docker`, `docker_volumes`, `mail`; `inside` — путь найденного родительского каталога; `size` — байты (`du`, `null` — не успели посчитать); `added` — СУБД уже добавлена как база этого сервера. `502` — нет SSH, `422` — скрипт не выполнился.

### Настройки бекапа сервера

Одни на сервер; пока не сохранены, `GET` отдаёт значения по умолчанию. Удаляются вместе с сервером. Сам запуск бекапов будет добавлен позже — сейчас хранятся только настройки.

```json
{"data": {"enabled": true, "paths": ["/var/www", "/etc/nginx"], "excludes": ["*.log", "/var/www/cache"],
  "schedule": "0 3 * * *", "timezone": "Europe/Moscow", "next_run_at": "2026-10-14T03:00:00+03:00",
  "pre_command": "mysqldump app > /var/backups/app.sql", "pre_command_timeout": 300, "pre_command_continue_on_error": false, "updated_at": "…"}}
```

- `paths` — абсолютные пути каталогов (до 50), `excludes` — пути или маски, которые не попадают в бекап (до 100). Включить бекап (`enabled: true`) можно только хотя бы с одним каталогом.
- `schedule` — cron-выражение из 5 полей, по умолчанию `0 3 * * *` (каждый день в 03:00); понимается в часовом поясе `BACKUP_TIMEZONE` (по умолчанию `Europe/Moscow`). `next_run_at` — ближайший запуск, `null`, если бекап выключен.
- `pre_command` — команда, которая выполняется на сервере по SSH перед бекапом (например, дамп базы в один из каталогов); если она завершится с ошибкой или не уложится в `pre_command_timeout` секунд (10–86400, по умолчанию 300), бекап не делается — либо делается всё равно при `pre_command_continue_on_error: true`. Пустая строка — без команды. Шаблоны частых команд — `GET /backup/pre-command-templates` (`config/backup.php`).
- Обзор файлов (`/files`) использует GNU `find`, на системах без него (busybox) — запасной вариант на shell; в ответе не больше `browse_limit` (2000) элементов, сначала каталоги.
- В списке и карточке сервера — краткая сводка `backup: {enabled, schedule, paths_count, next_run_at}`.

## API: базы данных

Подключение к СУБД (MySQL/MariaDB, PostgreSQL или ClickHouse) и настройки её бекапа — одна сущность `databases`. Доступно всем пользователям.

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /api/v1/databases?search=&server_id=&page=` | — | `200` — страница подключений по названию; `server_id` — подключения через этот сервер |
| `POST /api/v1/databases` | см. ниже | `201 {data: …}` |
| `GET /api/v1/databases/{id}` | — | `200 {data: …}` |
| `PATCH /api/v1/databases/{id}` (или `PUT`) | любые поля | `200 {data: …}` |
| `DELETE /api/v1/databases/{id}` | — | `204` (сама база на сервере не затрагивается) |
| `POST /api/v1/databases/{id}/provision-user` | `{username?, admin: {mode: local\|credentials, username?, password?}}` | `200 {data: {log, connection}}` — создать в СУБД пользователя для бекапов (только `via=ssh`) |
| `POST /api/v1/databases/{id}/check` | — | `200 {data: {status, error, version, databases, connection}}` |
| `GET /api/v1/databases/{id}/schemas` | — | `200 {data: [{name, size, system}]}` — базы на сервере СУБД |
| `GET /api/v1/databases/{id}/tables?database=shop` | — | `200 {data: [{name, rows, size}]}` — таблицы (для PostgreSQL — `схема.таблица`) |

Ошибки `schemas`/`tables`: `422` — ошибка СУБД (доступ, клиент не найден), `502` — не удалось подключиться по SSH.

**Подключение:** `name`, `description?`, `engine` (`mysql` | `postgres` | `clickhouse`), `via` (`ssh` — клиент `mysql`/`psql`/`clickhouse-client` запускается на сервере `server_id`, при `docker_container` — внутри контейнера через `docker exec`; `direct` — из SystemKeeper), `host`, `port` (по умолчанию 3306/5432/9000 — у ClickHouse нативный порт, не HTTP), `username`, `password?` (зашифрован, наружу только `has_password`; при изменении можно не передавать), `maintenance_database?` (PostgreSQL, по умолчанию `postgres`). Пароль передаётся клиенту через stdin и переменную окружения (`MYSQL_PWD`/`PGPASSWORD`/`CLICKHOUSE_PASSWORD`) — в аргументы процессов на сервере не попадает. Сервер, через который подключены базы, удалить нельзя (`409`).

**Бекап** (в ответе — объект `backup`): `backup_enabled`, `schedule` (cron, как у серверов), `databases` (пустой — все, кроме служебных), `exclude_tables` и `schema_only_tables` — таблицы «база.таблица» (PostgreSQL — «база.схема.таблица»), которые пропускаются или дампятся без данных, `options`:

- MySQL: `lock_mode` — `single_transaction` (по умолчанию: снимок без блокировки, для InnoDB), `lock_tables` (блокировка таблиц базы на чтение на время её дампа, для MyISAM), `none` (без блокировок, возможна неконсистентность); `routines`, `events`, `triggers` (по умолчанию `true`), `compress`.
- PostgreSQL: `format` — `custom` (для `pg_restore`, по умолчанию) или `plain` (SQL), `no_owner` (без владельцев и прав), `compress`. pg_dump всегда делает консистентный снимок без блокировки записи.
- ClickHouse: `compress`. По каждой базе — `<база>.schema.sql` (CREATE DATABASE и таблицы), `<база>.<таблица>.native.gz` (данные в формате Native; только у таблиц, которые хранят данные — MergeTree, Log, Memory…) и `<база>.views.sql` (представления, материализованные представления, словари — создаются после загрузки данных, иначе MV посчитают их повторно). Транзакций нет: таблицы выгружаются по очереди. Данные MV без `TO` не сохраняются. Автонастройки пользователя нет — нужен пользователь с `SELECT` и `SHOW`. Для `via=direct` нужен clickhouse-client в контейнере SystemKeeper (в образ не входит), поэтому подключайтесь через сервер.

Смена `engine` сбрасывает `options` к значениям по умолчанию.

### Автонастройка пользователя для бекапов

`POST /databases/{id}/provision-user` подключается к серверу по SSH и создаёт в СУБД пользователя (по умолчанию `systemkeeper_backup`) с правами только на чтение и случайным паролем из 32 символов, сохраняет логин и пароль в подключении и проверяет вход под ним. Если пользователь уже есть, ему задаётся новый пароль.

- MySQL/MariaDB: `SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT, PROCESS ON *.*` (+ `SHOW_ROUTINE` в MySQL 8.0.20+) для `localhost`/`127.0.0.1`/`::1`, если хост СУБД локальный, иначе для `%`.
- PostgreSQL 14+: роль с `LOGIN` и членством в `pg_read_all_data`.
- Права администратора: `admin.mode = local` — root MySQL по сокету или пользователь `postgres` (от root или через `sudo -n`), в Docker — root-пароль из `MYSQL_ROOT_PASSWORD`/`MARIADB_ROOT_PASSWORD` или `POSTGRES_USER`; `credentials` — логин и пароль администратора, используются один раз и не сохраняются.
- SQL и все пароли передаются через stdin команды, в аргументы процессов на сервере не попадают.

## API: мониторинг доступности

Мониторы проверяют сайты и сервисы по расписанию. Планировщик каждую минуту ставит в очередь проверки мониторов, которым подошло время (`monitors:dispatch-due`); проверки старше 30 дней удаляются раз в сутки (`monitors:prune`).

| Метод | Путь | Описание |
|---|---|---|
| GET | `/monitors` | Список по названию; `project_id` (`none` — без проекта), `status` (`up`, `down`, `pending`, `paused`), `per_page` |
| POST | `/monitors` | Создать (поля ниже); `201` |
| GET | `/monitors/{id}` | Монитор |
| PATCH | `/monitors/{id}` | Изменить часть полей; пауза — `{"enabled": false}` |
| DELETE | `/monitors/{id}` | Удалить вместе с проверками и сбоями; `204` |
| POST | `/monitors/test` | Разовая проверка по полям монитора без сохранения → `{data: {status, response_ms, status_code, message}}` |
| POST | `/monitors/{id}/check` | Проверить сейчас → то же и `data.monitor` (текущий монитор). Результат только возвращается: проверка не записывается, статус, сбои и `last_check_at` не меняются |
| GET | `/monitors/{id}/heartbeats` | Проверки за `period` = `24h` (по умолчанию), `7d`, `30d`, старые → новые; на `7d`/`30d` усреднены по часу / 3 часа (`down`, если в интервале была недоступность). У проверки есть `id` (в `recent` и на `24h`), у усреднённых он `null` |
| DELETE | `/monitors/{id}/heartbeats/{heartbeat}` | Удалить одну проверку; `204`, `404` — если она у другого монитора |
| DELETE | `/monitors/{id}/heartbeats` | Очистить историю проверок; `?incidents=1` — вместе со сбоями; `204` |
| DELETE | `/monitors/{id}/incidents/{incident}` | Удалить один сбой; `204`, `404` — если он у другого монитора |
| GET | `/monitors/{id}/incidents` | Сбои, новые сверху (`per_page`); `ended_at: null` — сбой продолжается |

Поля монитора: `name`, `project_id`, `type` (`http`, `tcp`, `ping`, `dns`), `enabled`; `http` — `url`, `method`, `accepted_statuses` («200-299», «301»), `keyword` и `keyword_invert`, `headers` (`[{name, value}]`), `body`, `follow_redirects`, `ignore_tls`, `ssl_expiry_days`; `tcp`/`ping`/`dns` — `hostname` (у `tcp` ещё `port`); `dns` — `dns_record_type` (`A`, `AAAA`, `CNAME`, `MX`, `TXT`, `NS`), `dns_resolver` (IP; пусто — системный), `dns_expected`; общие — `interval` (20…86400 с), `timeout`, `retries` (0…10), `retry_interval`.

В ответе дополнительно: `status` (`up`, `down`, `pending`, `paused`), `status_since`, `last_check_at`, `last_response_ms`, `last_error`, `uptime` (`h24`, `d7`, `d30`, %; `null` — нет данных), `avg_response_ms` (за 24 часа), `ssl` (`valid_to`, `issuer`, `days_left`; только https), `recent` (до 50 последних проверок: `at`, `status`, `response_ms`, `status_code`, `message`).

- Монитор становится `down`, когда неудач подряд больше `retries` (0 — сразу); пока они копятся, статус `pending`, а следующая проверка — через `retry_interval`. Первая ошибка сбоя сохраняется в инциденте; первый успех закрывает его.
- Удаление проверок не меняет статус монитора; uptime и среднее время ответа считаются по оставшимся проверкам.
- Uptime — доля `up` среди `up` и `down`; повторные проверки (`pending`) не учитываются.
- Сертификат: истёкший — `down` (если не `ignore_tls`); истекающий раньше чем через `ssl_expiry_days` — монитор `up` с предупреждением в `message`.
- Значения заголовков хранятся в БД зашифрованно и не попадают в журнал и сообщения проверок; логин и пароль из адреса в текстах ошибок вырезаются.

## API: ресурсы серверов

Показатели снимаются по SSH с уже настроенного подключения сервера — одним вызовом shell-скрипта, который читает `/proc` (CPU, нагрузка, память, сеть, uptime), `df` и `/etc/os-release`. Нужен Linux; ничего устанавливать на сервер не надо. Планировщик каждую минуту ставит в очередь сбор для серверов, которым подошло время (`metrics:dispatch-due`; во время обновления SystemKeeper пропускается); история старше срока хранения сервера удаляется раз в сутки (`metrics:prune`). У новых и уже существующих серверов сбор включён по умолчанию с интервалом 60 секунд.

| Метод | Путь | Описание |
|---|---|---|
| GET | `/metrics/servers` | Показатели всех серверов по названию → `{data: ServerMetrics[]}` (без пагинации) |
| GET | `/servers/{id}/metrics` | Показатели сервера и настройки сбора → `{data: ServerMetrics}` |
| GET | `/servers/{id}/metrics/history` | История → `{data: MetricPoint[]}`, старые → новые; `period` = `1h`, `24h` (по умолчанию), `7d`, `30d` |
| PUT, PATCH | `/servers/{id}/metrics/settings` | Настройки сбора → `{data: ServerMetrics}`; `422` при неверных значениях |

Настройки (`settings`): `enabled`, `interval` (30…3600 с), `retention_days` (1…365), `disks_exclude` — точки монтирования, которые не показывать: точное совпадение, шаблон (`/snap/*`) или родитель (`/snap` скрывает и `/snap/core`). Включение и смена интервала действуют со следующей минуты.

`ServerMetrics`: `server` (`id`, `name`, `host`, `project`), `status`, `collected_at`, `error`, `settings`, `cpu` (%), `cpu_cores`, `load` (1, 5 и 15 минут), `memory` и `swap` (`used`, `total` в байтах; у сервера без swap `total` равен 0), `disks` (`mount`, `device`, `used`, `total`), `network` (`rx`, `tx` в байтах в секунду), `uptime` (секунды), `os`, `spark` (`cpu` и `memory` за последний час, % — для мини-графиков).

Статус: `ok` — данные свежие; `stale` — нет данных дольше трёх интервалов (или сервер ещё не опрашивался); `unreachable` — последний сбор не удался, в `error` причина (показатели и `collected_at` остаются от последнего удачного); `disabled` — сбор выключен.

`MetricPoint`: `at`, `cpu`, `memory`, `swap`, `load1`, `rx`, `tx`, `disks` (заполненность в % по точке монтирования, объект). Точки `1h` отдаются как есть; на `24h`, `7d` и `30d` значения усредняются по интервалам (5 минут, 34 минуты, 2,4 часа) — около 300 точек, а `disks` берётся из последней точки интервала.

- CPU — доля непростаивающего времени за секунду между двумя замерами `/proc/stat`; память — `MemTotal − MemAvailable`; сеть — сумма внешних интерфейсов (без `lo`, `veth*`, `docker*`, `br-*` и подобных).
- Диски: без `tmpfs`, `devtmpfs`, `overlay`, `squashfs` и подобных; одно устройство, смонтированное в нескольких местах, показывается один раз.
- Сбор — отдельная задача в обычной очереди, не накладывается на сбор того же сервера; пока идёт сбор, один SSH-вызов занимает около секунды.

## API: алерты

Алерты сообщают, что что-то сломалось: сервер перегружен или не отвечает, сайт упал, сертификат истекает, бекап не удался или давно не было, хранилище заполнено. Планировщик каждую минуту оценивает все включённые правила (`alerts:evaluate`; во время обновления SystemKeeper пропускается); решённые алерты старше 90 дней удаляются раз в сутки (`alerts:prune`). Права — как у остальных ресурсов: все авторизованные пользователи.

### Каналы уведомлений

| Метод | Путь | Описание |
|---|---|---|
| GET | `/alert-channels` | Все каналы → `{data: AlertChannel[]}` (без пагинации) |
| POST | `/alert-channels` | Создать; `201`. Канал «Почта» при ненастроенной почте — `422` с текстом «Почта не настроена: Настройки → Уведомления» в `errors.type` |
| GET | `/alert-channels/{id}` | Канал |
| PATCH | `/alert-channels/{id}` | Изменить часть полей; секреты (`bot_token`, `secret`) пустыми или без поля оставляют прежние |
| DELETE | `/alert-channels/{id}` | Удалить; канал пропадает из правил, журнал отправок в него удаляется; `204` |
| POST | `/alert-channels/{id}/test` | Тестовое сообщение → `{data: {ok, error}}`, всегда `200`; для канала «Почта» без настроенной почты — `422` |

Поля: `name`, `type` (`telegram`, `email`, `webhook`), `enabled`, `config`: `telegram` — `bot_token`, `chat_id`; `email` — `recipients` (до 20 адресов); `webhook` — `url` (http/https), `secret` (необязательный). Настройки хранятся в БД зашифрованно; в ответе секретов нет, только `has_token` (telegram) и `has_secret` (webhook). Если у существующего канала меняется `type`, прежние настройки отбрасываются и новые обязательны. В ответе также `rules_count`, `last_sent_at`, `last_error` (ошибка последней отправки; успешная её сбрасывает).

Формат один для всех событий (сработал, напоминание, решено, тест) — его строит `App\Services\Alerts\AlertMessage`, каналы только отрисовывают: эмодзи и цвет по статусу (🔴 критично, 🟠 предупреждение, ✅ решено, 🧪 тест), заголовок, сообщение, объект, проект, правило, условие, начало и длительность, ссылка на объект (по `APP_URL`).

- **Telegram** — `sendMessage` Bot API с токеном бота в адресе, `parse_mode=HTML`, `disable_web_page_preview`. Жирный заголовок, сообщение, строки «Объект / Проект / Правило / Начало / Длительность», ссылка «Открыть в SystemKeeper». Пользовательские данные экранируются (`&`, `<`, `>`), длинные значения обрезаются, чтобы уложиться в лимит 4096 знаков.
- **Почта** — письмо multipart: HTML (таблицы и inline-стили, цветная полоса по статусу, крупное сообщение, таблица деталей, кнопка «Открыть в SystemKeeper», подвал с правилом; в тёмной теме не ломается) и текстовая версия. Тема: `[КРИТИЧНО] Сервер web-1: CPU 97%`, `[ПРЕДУПРЕЖДЕНИЕ] …`, `[КРИТИЧНО · НЕ РЕШЕНО] …`, `[РЕШЕНО] …`, `[ТЕСТ] …`. Отправка через SMTP из «Настройки → Уведомления» или `MAIL_*`.
- **Вебхук** — `POST` JSON `{event, alert: {id, status, severity, message, rule, object, started_at, resolved_at}, title, text, markdown, url}`; `text` — простой текст, `markdown` — то же в Markdown (пользовательские данные экранированы) для Slack, n8n и т. п.; `event` — `firing`, `repeat`, `resolved` или `test`. Если задан `secret`, в заголовке `X-Signature` — HMAC-SHA256 (hex) от точного тела запроса. Успех — любой `2xx`.
- Токен бота и секрет вырезаются из сообщений об ошибках (в том числе сетевых).

### Правила

| Метод | Путь | Описание |
|---|---|---|
| GET | `/alert-rules` | Все правила по названию → `{data: AlertRule[]}`; `project_id` — только правила этого проекта |
| POST | `/alert-rules` | Создать (поля ниже); `201` |
| GET | `/alert-rules/{id}` | Правило |
| PATCH | `/alert-rules/{id}` | Изменить часть полей; включить/выключить — `{"enabled": false}` |
| DELETE | `/alert-rules/{id}` | Удалить вместе с алертами правила; `204` |
| POST | `/alert-rules/{id}/test` | Тестовое уведомление по правилу → `{data: {results: [{channel: {id, name, type}, ok, error}]}}`; не больше 10 в минуту |

`POST /alert-rules/{id}/test` синхронно (не через очередь) шлёт во все каналы правила, в том числе выключенные, пример уведомления именно этого правила: вид, важность, условие, первый подходящий объект из области правила (нет объектов — «Пример объекта»), пометка «Тестовое уведомление», ссылка на правило. Алерт в БД не создаётся, `last_fired_at` не меняется; у канала обновляются `last_sent_at` / `last_error`. Ошибка канала — не ошибка запроса: ответ `200`, у канала `ok: false` и `error` (токены и секреты вырезаны). `422` (`errors.channel_ids`) — у правила нет каналов; `404` — правила нет; `429` — лимит.

Поля: `name`, `enabled`, `kind`, `severity` (`warning`, `critical`), `threshold`, `duration` (минут, 0…1440), `project_id`, `targets` (`[{type, id}]`), `channel_ids`, `repeat_every` (минут, 5…43200; `null` — один раз), `notify_resolved`. В ответе дополнительно `project`, `objects` (targets с названиями), `channels` (`id`, `name`, `type`), `firing_count`, `last_fired_at`, `created_at`.

| `kind` | Объекты | Порог | Срабатывает, когда |
|---|---|---|---|
| `server_cpu` | server | % (1…100) | CPU выше порога |
| `server_memory` | server | % (1…100) | память занята больше порога |
| `server_disk` | server | % (1…100) | любой диск заполнен больше порога (в сообщении — самый заполненный) |
| `server_load` | server | на ядро (0,1…100) | load average за 5 минут, делённый на число ядер, выше порога |
| `server_unreachable` | server | — | метрики не удаётся снять по SSH (статус `unreachable`) |
| `monitor_down` | monitor | — | статус монитора `down` |
| `monitor_ssl` | monitor | дни (1…90) | до конца сертификата монитора осталось меньше порога |
| `backup_failed` | server, database | — | последний завершённый бекап источника неудачный |
| `backup_missing` | server, database | часы (1…720) | у источника с включённым бекапом нет успешного бекапа дольше порога (если не было ни одного — от создания настроек бекапа) |
| `storage_full` | storage | % (1…100) | диск SystemKeeper или Яндекс Диск заполнен больше порога |

Область: `project_id` — только объекты проекта (`null` — все; у `storage_full` игнорируется — хранилища общие); `targets` — только выбранные объекты (пусто — все объекты нужного типа в области). Типы в `targets` должны подходить к `kind`, объекты — существовать (`422` на `targets`). Порог обязателен у видов, где он есть, у остальных сбрасывается в `null`. Удаление проекта удаляет его правила, а не расширяет их на все проекты.

Показатели серверов берутся из «Ресурсов серверов» (см. выше), мониторов — из состояния монитора, бекапов — из истории запусков. Если свежих данных нет (метрики устарели, сбор выключен, Яндекс Диск недоступен, у хранилища S3 нет размера), состояние объекта «неизвестно»: алерт не создаётся и не закрывается.

### Рекомендуемые правила

Готовый базовый набор алертов на все проекты и все объекты. Предлагается только то, для чего есть объекты, и чего ещё не покрывает включённое правило того же `kind` на все проекты без `targets` (у видов с порогом — с той же `severity`: правило «диск 85% warning» не закрывает «диск 95% critical»).

| Метод | Путь | Описание |
|---|---|---|
| GET | `/alert-rules/suggestions` | Что можно добавить сейчас → `{data: AlertSuggestion[]}`; пусто, если всё покрыто или отслеживать нечего |
| POST | `/alert-rules/suggestions/apply` | `{keys: string[], channel_ids: number[]}` → `201 {data: AlertRule[]}`: создаёт выбранные правила (включённые, с этими каналами) с теми же проверками, что `POST /alert-rules`. `422`: неизвестный ключ или правило, которое уже покрыто (`keys`); нет `channel_ids` или такого канала; каналов в системе нет вовсе (текст «Сначала создайте канал уведомлений…»). Всё или ничего — при ошибке ни одно правило не создаётся |

`AlertSuggestion`: `key`, `name`, `kind`, `severity`, `threshold`, `duration`, `project_id` (всегда `null`), `targets` (всегда `[]`), `repeat_every` (`60` у `critical`, у `warning` — `null`), `notify_resolved` (`true`), `reason` («3 сервера с метриками»), `objects_count` (сколько объектов попадёт под правило). Значения совпадают с `ALERT_KINDS.defaults` во фронте; добавочно — диск 95%.

| `key` | Правило | Предлагается, если есть |
|---|---|---|
| `server_unreachable` | не отвечает, `critical`, 3 мин | серверы с включённым сбором метрик (сервер без своих настроек сбора считается с включённым) |
| `server_disk_warning`, `server_disk_critical` | диск > 85% `warning` / > 95% `critical` | то же |
| `server_memory` | память > 90% 5 мин | то же |
| `server_cpu` | CPU > 90% 5 мин | то же |
| `server_load` | load на ядро > 2, 10 мин | то же |
| `monitor_down` | монитор недоступен, `critical`, сразу | включённые мониторы |
| `monitor_ssl` | сертификат: меньше 14 дн. | включённые HTTP-мониторы с адресом `https://` |
| `backup_failed` | бекап не удался, `critical` | серверы и базы с включённым бекапом по расписанию |
| `backup_missing` | нет успешного бекапа > 26 ч | то же |
| `storage_full` | хранилище > 90% | хранилища «Диск SystemKeeper» и «Яндекс Диск» (у S3 размера нет) |

### Движок

- Условие должно продержаться `duration` минут (отсчёт ведётся по паре «правило + объект» и сбрасывается, когда условие пропадает), затем создаётся алерт `firing` и уведомление уходит во все включённые каналы правила. На пару «правило + объект» — один активный алерт; пока он активен, его `message` обновляется.
- Пока алерт активен и не взят в работу (`ack`), а у правила есть `repeat_every`, уведомление повторяется с этим интервалом.
- Условие ушло — алерт становится `resolved` и, если `notify_resolved`, уходит уведомление «Решено».
- Выключенное правило, удалённый объект и объект, выпавший из области правила, закрывают алерты молча — без уведомления.
- Уведомления отправляются в очереди; результат каждой отправки (`ok` / `error`) — в `notifications` алерта и в `last_error` канала. Сбой канала не влияет ни на другие каналы, ни на цикл оценки. Повторной отправки по ошибке нет — её заменяет `repeat_every`.
- Сообщение: «CPU 97% дольше 5 мин», «Диск /data заполнен на 91%», «Сайт example.com недоступен: <ошибка>», дальше — правило, объект, время начала и ссылка на объект в SystemKeeper (от `APP_URL`).

### Срабатывания

| Метод | Путь | Описание |
|---|---|---|
| GET | `/alerts` | Алерты с пагинацией: `status` (`firing`, `resolved`), `project_id`, `severity`, `per_page` (до 100, по умолчанию 25), `page`. Активные — сначала критичные, затем новые; история — по времени решения |
| GET | `/alerts/summary` | `{data: {firing, critical}}` — счётчик активных для меню |
| POST | `/alerts/{id}/ack` | Взять в работу: повторные уведомления прекращаются; повторный вызов ничего не меняет; `409`, если алерт уже решён |

Алерт: `id`, `rule` (`id`, `name`, `kind`), `severity`, `status`, `object` (`type`, `id`, `name`, `project`), `message`, `started_at`, `resolved_at`, `acknowledged_at`, `acknowledged_by` (`id`, `name`), `notifications` (`channel`, `event`, `at`, `ok`, `error`). Название и проект объекта сохраняются в алерте, поэтому история остаётся после удаления объекта.

## API: настройки уведомлений

Как SystemKeeper отправляет письма и подключается к Telegram. **Только администратор** (остальным `403`). Хранится в таблице `settings`, значение зашифровано целиком. Пароли наружу не отдаются никогда — только признаки `has_password`.

| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /api/v1/settings/notifications` | — | `200 {data: NotificationSettings}` |
| `PUT /api/v1/settings/notifications` (или `PATCH`) | `NotificationSettingsInput` | `200 {data: NotificationSettings}` — то же, что `GET` |
| `POST /api/v1/settings/notifications/test-mail` | `{to}` | `200 {data: {ok, error}}`; `422`, если почта не настроена или `to` не адрес |
| `POST /api/v1/settings/notifications/test-telegram` | — | `200 {data: {ok, error}}` |

```jsonc
// NotificationSettings
{"data": {
  "mail": {"configured": true, "host": "smtp.example.com", "port": 465, "encryption": "ssl",   // none | tls | ssl
           "username": "robot@example.com", "has_password": true,
           "from_address": "alerts@example.com", "from_name": "SystemKeeper",
           "source": "settings"},                                                              // settings | env | none
  "telegram": {"api_url": "https://api.telegram.org",
               "proxy": {"enabled": true, "type": "socks5",                                    // http | socks5
                         "host": "10.0.0.5", "port": 1080, "username": "u", "has_password": true}}
}}
```

- **Источник почты** (`mail.source`): `settings` — задан `mail.host`, письма идут через этот SMTP с адресом отправителя из настроек; `env` — хоста в настройках нет, но на сервере задан реальный `MAIL_MAILER` (не `log` и не `array`), письма идут через `MAIL_*` из `.env`; `none` — почта не настроена (`configured: false`), письма некуда отправлять. Пустой `mail.host` при сохранении возвращает почту на `.env`.
- **Шифрование:** `ssl` — SSL/TLS с первого байта (обычно порт 465), `tls` — обязательный STARTTLS (587), `none` — без шифрования (25).
- **`PUT`:** разделы `mail` и `telegram` и поля внутри можно слать частично — что не передано, не меняется; пустые текстовые поля сбрасываются (`telegram.api_url` пустой — снова `https://api.telegram.org`). Если задан `mail.host` — обязателен `mail.from_address`; при `telegram.proxy.enabled` — `host` и `port`.
- **Пароли** (`mail.password`, `telegram.proxy.password`): отсутствующее или пустое поле оставляет прежний пароль (пустая строка и `null` для API — одно и то же); новое значение заменяет. Стереть сохранённый пароль — `clear_password: true` в том же разделе (`mail.clear_password`, `telegram.proxy.clear_password`); если одновременно передан новый пароль, он важнее.
- **Прокси Telegram:** `http` → `http://user:pass@host:port`, `socks5` → `socks5h://…` (имена резолвит прокси); логин и пароль кодируются. Прокси и `api_url` действуют для отправки в каналы Telegram и для `test-telegram`.
- **`test-mail`** шлёт письмо с сохранённой конфигурацией (SMTP из настроек или `.env`). **`test-telegram`** не требует токена: делает `GET {api_url}` (через прокси, если он включён, без редиректов) — любой HTTP-ответ считается успехом, ошибка соединения — `ok: false` с текстом в `error`. Оба — не чаще 10 раз в минуту.
- Тексты ошибок отправки (в `error`, в канале и в алерте) очищаются от паролей SMTP и прокси и токена бота.

## API: запуски бекапов

Бекап выполняется в очереди `backups` (отдельный воркер `backups` в `compose.yaml`, задача — до 4 часов). Файлы — в хранилище источника (см. «Диски и облака»); у локального — на диске `backups` (`storage/app/backups/{servers|databases}/{id}/{дата}-{id бекапа}/`).

| Метод | Путь | Описание |
|---|---|---|
| POST | `/servers/{id}/backups` | Запустить бекап сервера сейчас: `202` и бекап со статусом `queued`; `409` — бекап уже идёт; `422` — не выбраны каталоги |
| GET | `/servers/{id}/backups` | История бекапов сервера, новые сверху (`per_page` до 100) |
| POST | `/databases/{id}/backups` | Запустить бекап базы данных сейчас (`202` / `409`) |
| GET | `/databases/{id}/backups` | История бекапов базы данных |
| GET | `/backups/latest` | Сводка: `groups`, `projects`, `servers`, `databases`; у сервера и базы — `latest` (последний бекап: `id`, `status`, `trigger`, `size`, `error`, `started_at`, `finished_at`; `null` — бекапов нет), `last_success_at`, `schedule_enabled`, `total_size` и `total_count` (все хранящиеся удачные бекапы) |
| GET | `/backups/{id}` | Бекап: `status` (`queued`, `running`, `success`, `failed`), `trigger` (`manual`, `schedule`, `hook`), `user`, `started_at`, `finished_at`, `files` (`name`, `size`), `size`, `log` (`at`, `message`), `error` |
| GET | `/backups/{id}/files/{name}` | Скачать файл бекапа |
| DELETE | `/backups/{id}` | Удалить бекап с файлами (`409`, пока выполняется) |

- Сервер: команда перед бекапом (с её таймаутом и флагом «продолжать при ошибке»), затем `tar.gz` каталогов потоком по SSH в `files.tar.gz`. Пути в архиве — относительно `/`; исключения передаются в `--exclude`. GNU tar пропускает нечитаемые файлы, код 1 («файл изменился при чтении») — предупреждение в журнале, а не ошибка; BusyBox tar тоже поддерживается.
- База данных: по файлу на базу — `mysqldump` (`<база>.sql[.gz]`, с учётом `lock_mode`, исключённых и «только структура» таблиц) или `pg_dump` (`<база>.dump` в формате custom или `<база>.sql[.gz]`). Сжатие — на стороне сервера, пароль — через stdin. Если не удалась хотя бы одна база, бекап `failed`.
- Файлы неудачного бекапа удаляются (запись с журналом остаётся; хранится 20 последних неудачных). Бекап не запускается, если в локальном хранилище свободно меньше `BACKUP_MIN_FREE_SPACE` (по умолчанию 1 ГБ).
- При удалении сервера или базы данных удаляются и их бекапы с файлами.
- Чтобы восстановиться руками без SystemKeeper, в каждом месте хранения лежит описание: `{каталог бекапа}/backup.json` (источник, проект, файлы с SHA-256, команды восстановления), в корне — `index.json` (каталог `servers/5` / `databases/3` → сервер или база и список бекапов) и `README.md` для человека. Оглавление обновляется после каждого бекапа и удаления; перед записью перечитывается: меняется только запись своего источника и в ней только свои поля, чужие записи/поля сохраняются, в README заменяется только блок между метками `systemkeeper:begin/end`. Испорченный `index.json` откладывается в `index.json.broken-<дата>`. Обновления по одному хранилищу идут под блокировкой.
- Подробности бекапа — `meta` в `GET /backups/{id}`: `host` (hostname, os, kernel, tools — версия tar), `source` (что бекапилось: каталоги и исключения или СУБД с версией, параметрами дампа и таблицами), `pre_command` (команда, код выхода, длительность, хвост вывода), `archive` (`uncompressed_size` — объём до сжатия: из `tar --totals` или посчитанный при приёме), `databases` (по каждой базе: размер в СУБД, объём SQL, размер дампа, время, ошибка). У каждого файла — `sha256` и для `.gz` — `uncompressed_size`; всё считается на лету при приёме потока.

### Запуск по ссылке-хуку (CI/CD)

Секретная ссылка, `POST` на которую запускает бекап без входа — например, из CI/CD перед деплоем. Токен хранится зашифрованным, поиск — по SHA-256.

| Метод | Путь | Описание |
|---|---|---|
| GET | `/servers/{id}/backup-hook` | `{enabled, path, last_used_at, created_at}`; `path` — `/api/v1/hooks/{token}` |
| POST | `/servers/{id}/backup-hook` | Включить ссылку или выпустить новую (старая перестаёт работать), `201` |
| DELETE | `/servers/{id}/backup-hook` | Выключить ссылку |
| GET/POST/DELETE | `/databases/{id}/backup-hook` | То же для базы данных |
| POST | `/hooks/{token}` | **Без входа.** Запустить бекап: `202` и `{id, status, started_at, finished_at, size, error, status_url}`; `404` — ссылки нет; `409` — бекап уже идёт или обновляется система; `422` — не выбраны каталоги. Не чаще 30 раз в минуту |
| GET | `/hooks/{token}/backups/{id}` | **Без входа.** Ход бекапа этого источника (те же поля) |

```sh
curl -fsS -X POST https://backup.example.com/api/v1/hooks/skh_…
```

## API: диски и облака (хранилища)

| Метод | Путь | Описание |
|---|---|---|
| GET | `/storages` | Хранилища: `type` (`local` — диск SystemKeeper, `s3` — бакет S3, `yandex_disk` — папка на Яндекс Диске), `is_default`, `disk` (`total`, `free`, байт: диск сервера или квота Яндекс Диска, кеш 5 минут; у S3 — `null`), `s3` (`endpoint`, `region`, `bucket`, `prefix`, `path_style`, `key`, `has_secret`), `yandex_disk` (`folder`, `has_token`, `parallel`), `used` — занято бекапами, `backups_count` |
| POST | `/storages` | Подключить S3: `type: s3`, `name`, `endpoint` (пусто — Amazon), `region`, `bucket`, `prefix`, `path_style`, `key`, `secret`. Яндекс Диск: `type: yandex_disk`, `name`, `token` (OAuth), `folder` (`SystemKeeper` → `disk:/SystemKeeper`; `app:/…` — для токена с доступом только к папке приложения), `parallel` (1–10, по умолчанию 4 — сколько файлов Диск скачивает по ссылке одновременно) |
| GET | `/storages/{id}` | То же и `sources` — серверы и базы данных, бекапящиеся сюда: `type` (`server`/`database`), `id`, `name`, `project` (`id`, `name`, `group`), `used`, `backups_count`, `keep_count`, `max_size` |
| PATCH | `/storages/{id}` | Изменить; у диска — только `name`, у S3 `secret` и у Яндекс Диска `token` можно не передавать |
| DELETE | `/storages/{id}` | Отключить S3 (файлы в бакете остаются); `409` — диск SystemKeeper, в хранилище есть бекапы или его выбрали серверы/базы |
| POST | `/storages/{id}/check` | Проверка: записать, прочитать и удалить файл; итог в `data.status` (`ok`/`failed`) и `data.error` |
| GET | `/storages/{id}/sync` | Синхронизация: `pending` `{backups, size}` — сколько бекапов есть в других хранилищах, но нет здесь; `last` — последний запуск `{status, total, done, failed, bytes_total, bytes_done, current, error, …}` или `null` |
| POST | `/storages/{id}/sync` | Скопировать сюда все такие бекапы, в фоне (`202`). `add_to_sources: true` — новые бекапы этих серверов и баз тоже сохранять сюда. `422` — синхронизация уже идёт или копировать нечего |

Настройки S3 хранятся зашифрованными (`encrypted:array`), секретный ключ в ответах не отдаётся. Бекап сначала пишется на локальный диск (поэтому проверка свободного места — всегда по нему), после успешного снятия копия раскладывается по всем выбранным местам: в бакеты загружается, на диске остаётся, только если он выбран. У бекапа — `copies` (`storage_id`, `name`, `type`, `status`, `error`); бекап удачный, если сохранился хотя бы в одно место, неудачные копии — предупреждением в журнале. Скачивание — из удачной копии (диск в приоритете) или `?storage_id=`. Скачивание из S3 и Яндекс Диска идёт потоком через SystemKeeper.

Яндекс Диск работает через REST API (`cloud-api.yandex.net`) с OAuth-токеном своего приложения на oauth.yandex.ru (права «Яндекс.Диск REST API»: информация о Диске и папка приложения или чтение и запись всего Диска). Файлы от 8 МБ Диск скачивает сам по временной ссылке (`POST /resources/upload?url=`) — так в разы быстрее загрузки через SystemKeeper: при синхронизации из S3 это временная ссылка бакета, иначе — подписанная ссылка SystemKeeper `GET /api/v1/transfers/{storage}/{path}` (без входа, один файл бекапа, живёт `SK_TRANSFER_LINK_TTL` минут, по умолчанию 180). Для этого адрес SystemKeeper должен открываться из интернета: `SK_PUBLIC_URL`, по умолчанию `APP_URL`; localhost и внутренние адреса не используются. Если ссылки нет или Диск не смог скачать — файл загружается потоком (поток без известного размера, например из S3, сначала копируется во временный файл). Отключить скачивание по ссылке — `YANDEX_DISK_FETCH_BY_URL=false`. Одновременно идёт до `parallel` скачиваний: и файлы одного бекапа, и файлы разных бекапов при синхронизации; бекап отмечается скопированным, когда загрузились все его файлы. Запросы с 429/5xx повторяются, после загрузки размер сверяется с Диском.

Настройки хранения задаются у каждого источника — в `PUT /servers/{id}/backup-settings` и `PATCH /databases/{id}`, в ответе — объект `retention`:

- `storage_ids` — куда сохранять, можно несколько мест (пусто — в место по умолчанию);
- `keep_count` — сколько последних успешных бекапов хранить (1–1000, по умолчанию 7);
- `max_size` — лимит объёма всех бекапов источника, байт (от 1 МБ; `null` — без лимита).

После каждого бекапа лишние удаляются: сначала сверх `keep_count`, затем самые старые, пока объём больше `max_size`. Самый новый успешный бекап не удаляется никогда — если он один больше лимита, в журнале будет предупреждение.


Синхронизация копирует каждый бекап из хранилища, где он уже лежит (сначала с диска SystemKeeper), сверяет размер файлов, кладёт `backup.json` и обновляет оглавление (`index.json`, `README.md`). Ошибка одного бекапа не останавливает остальные: у него появляется неудачная копия, а повторный запуск докопирует только недостающее.

## API: версия и обновление

- `GET /system/version` — `{current, latest: {version, name, notes, url, published_at} | null, update_available, checked_at, check_error, busy: {backups, syncs}, updater: {available, status, message, from, to, updated_at}}`. `updater.available` — запущен ли контейнер `updater`; `status`: `idle`, `pending`, `waiting` (ждёт бекапы), `backup`, `pulling`, `restarting`, `done`, `failed`, `rolled_back`.
- `GET /system/changelog` — «Что нового»: `[{version, name, notes (Markdown), url, published_at, is_new, is_current}]`, новые сверху. Описания — из `CHANGELOG.md` сборки, версии новее — из релизов GitHub.
- `POST /system/check` — проверить релизы сейчас (администратор, не чаще 10 раз в минуту).
- `POST /system/update` — обновиться до последней версии (администратор), `202`; `422`, если обновлений нет, обновлятор не запущен, обновление уже идёт или идут бекапы/синхронизации (`busy`). Пока идёт обновление, запуск бекапа отвечает `409`, синхронизации — `422`.
