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

Подключение к СУБД (MySQL/MariaDB или PostgreSQL) и настройки её бекапа — одна сущность `databases`. Доступно всем пользователям.

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

**Подключение:** `name`, `description?`, `engine` (`mysql` | `postgres`), `via` (`ssh` — клиент `mysql`/`psql` запускается на сервере `server_id`, при `docker_container` — внутри контейнера через `docker exec`; `direct` — из SystemKeeper), `host`, `port` (по умолчанию 3306/5432), `username`, `password?` (зашифрован, наружу только `has_password`; при изменении можно не передавать), `maintenance_database?` (PostgreSQL, по умолчанию `postgres`). Пароль передаётся клиенту через stdin и переменную окружения (`MYSQL_PWD`/`PGPASSWORD`) — в аргументы процессов на сервере не попадает. Сервер, через который подключены базы, удалить нельзя (`409`).

**Бекап** (в ответе — объект `backup`): `backup_enabled`, `schedule` (cron, как у серверов), `databases` (пустой — все, кроме служебных), `exclude_tables` и `schema_only_tables` — таблицы «база.таблица» (PostgreSQL — «база.схема.таблица»), которые пропускаются или дампятся без данных, `options`:

- MySQL: `lock_mode` — `single_transaction` (по умолчанию: снимок без блокировки, для InnoDB), `lock_tables` (блокировка таблиц базы на чтение на время её дампа, для MyISAM), `none` (без блокировок, возможна неконсистентность); `routines`, `events`, `triggers` (по умолчанию `true`), `compress`.
- PostgreSQL: `format` — `custom` (для `pg_restore`, по умолчанию) или `plain` (SQL), `no_owner` (без владельцев и прав), `compress`. pg_dump всегда делает консистентный снимок без блокировки записи.

Смена `engine` сбрасывает `options` к значениям по умолчанию.

### Автонастройка пользователя для бекапов

`POST /databases/{id}/provision-user` подключается к серверу по SSH и создаёт в СУБД пользователя (по умолчанию `systemkeeper_backup`) с правами только на чтение и случайным паролем из 32 символов, сохраняет логин и пароль в подключении и проверяет вход под ним. Если пользователь уже есть, ему задаётся новый пароль.

- MySQL/MariaDB: `SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT, PROCESS ON *.*` (+ `SHOW_ROUTINE` в MySQL 8.0.20+) для `localhost`/`127.0.0.1`/`::1`, если хост СУБД локальный, иначе для `%`.
- PostgreSQL 14+: роль с `LOGIN` и членством в `pg_read_all_data`.
- Права администратора: `admin.mode = local` — root MySQL по сокету или пользователь `postgres` (от root или через `sudo -n`), в Docker — root-пароль из `MYSQL_ROOT_PASSWORD`/`MARIADB_ROOT_PASSWORD` или `POSTGRES_USER`; `credentials` — логин и пароль администратора, используются один раз и не сохраняются.
- SQL и все пароли передаются через stdin команды, в аргументы процессов на сервере не попадают.

## API: запуски бекапов

Бекап выполняется в очереди `backups` (отдельный воркер `backups` в `compose.yaml`, задача — до 4 часов). Файлы — в хранилище источника (см. «Диски и облака»); у локального — на диске `backups` (`storage/app/backups/{servers|databases}/{id}/{дата}-{id бекапа}/`).

| Метод | Путь | Описание |
|---|---|---|
| POST | `/servers/{id}/backups` | Запустить бекап сервера сейчас: `202` и бекап со статусом `queued`; `409` — бекап уже идёт; `422` — не выбраны каталоги |
| GET | `/servers/{id}/backups` | История бекапов сервера, новые сверху (`per_page` до 100) |
| POST | `/databases/{id}/backups` | Запустить бекап базы данных сейчас (`202` / `409`) |
| GET | `/databases/{id}/backups` | История бекапов базы данных |
| GET | `/backups/{id}` | Бекап: `status` (`queued`, `running`, `success`, `failed`), `trigger` (`manual`, `schedule`), `user`, `started_at`, `finished_at`, `files` (`name`, `size`), `size`, `log` (`at`, `message`), `error` |
| GET | `/backups/{id}/files/{name}` | Скачать файл бекапа |
| DELETE | `/backups/{id}` | Удалить бекап с файлами (`409`, пока выполняется) |

- Сервер: команда перед бекапом (с её таймаутом и флагом «продолжать при ошибке»), затем `tar.gz` каталогов потоком по SSH в `files.tar.gz`. Пути в архиве — относительно `/`; исключения передаются в `--exclude`. GNU tar пропускает нечитаемые файлы, код 1 («файл изменился при чтении») — предупреждение в журнале, а не ошибка; BusyBox tar тоже поддерживается.
- База данных: по файлу на базу — `mysqldump` (`<база>.sql[.gz]`, с учётом `lock_mode`, исключённых и «только структура» таблиц) или `pg_dump` (`<база>.dump` в формате custom или `<база>.sql[.gz]`). Сжатие — на стороне сервера, пароль — через stdin. Если не удалась хотя бы одна база, бекап `failed`.
- Файлы неудачного бекапа удаляются (запись с журналом остаётся; хранится 20 последних неудачных). Бекап не запускается, если в локальном хранилище свободно меньше `BACKUP_MIN_FREE_SPACE` (по умолчанию 1 ГБ).
- При удалении сервера или базы данных удаляются и их бекапы с файлами.
- Чтобы восстановиться руками без SystemKeeper, в каждом месте хранения лежит описание: `{каталог бекапа}/backup.json` (источник, проект, файлы с SHA-256, команды восстановления), в корне — `index.json` (каталог `servers/5` / `databases/3` → сервер или база и список бекапов) и `README.md` для человека. Оглавление обновляется после каждого бекапа и удаления; перед записью перечитывается: меняется только запись своего источника и в ней только свои поля, чужие записи/поля сохраняются, в README заменяется только блок между метками `systemkeeper:begin/end`. Испорченный `index.json` откладывается в `index.json.broken-<дата>`. Обновления по одному хранилищу идут под блокировкой.
- Подробности бекапа — `meta` в `GET /backups/{id}`: `host` (hostname, os, kernel, tools — версия tar), `source` (что бекапилось: каталоги и исключения или СУБД с версией, параметрами дампа и таблицами), `pre_command` (команда, код выхода, длительность, хвост вывода), `archive` (`uncompressed_size` — объём до сжатия: из `tar --totals` или посчитанный при приёме), `databases` (по каждой базе: размер в СУБД, объём SQL, размер дампа, время, ошибка). У каждого файла — `sha256` и для `.gz` — `uncompressed_size`; всё считается на лету при приёме потока.

## API: диски и облака (хранилища)

| Метод | Путь | Описание |
|---|---|---|
| GET | `/storages` | Хранилища: `type` (`local` — диск SystemKeeper, `s3` — бакет S3), `is_default`, `disk` (`total`, `free`, байт; у S3 — `null`), `s3` (`endpoint`, `region`, `bucket`, `prefix`, `path_style`, `key`, `has_secret`), `used` — занято бекапами, `backups_count` |
| POST | `/storages` | Подключить S3: `type: s3`, `name`, `endpoint` (пусто — Amazon), `region`, `bucket`, `prefix`, `path_style`, `key`, `secret` |
| GET | `/storages/{id}` | То же и `sources` — серверы и базы данных, бекапящиеся сюда: `type` (`server`/`database`), `id`, `name`, `project` (`id`, `name`, `group`), `used`, `backups_count`, `keep_count`, `max_size` |
| PATCH | `/storages/{id}` | Изменить; у диска — только `name`, у S3 `secret` можно не передавать |
| DELETE | `/storages/{id}` | Отключить S3 (файлы в бакете остаются); `409` — диск SystemKeeper, в хранилище есть бекапы или его выбрали серверы/базы |
| POST | `/storages/{id}/check` | Проверка: записать, прочитать и удалить файл; итог в `data.status` (`ok`/`failed`) и `data.error` |
| GET | `/storages/{id}/sync` | Синхронизация: `pending` `{backups, size}` — сколько бекапов есть в других хранилищах, но нет здесь; `last` — последний запуск `{status, total, done, failed, bytes_total, bytes_done, current, error, …}` или `null` |
| POST | `/storages/{id}/sync` | Скопировать сюда все такие бекапы, в фоне (`202`). `add_to_sources: true` — новые бекапы этих серверов и баз тоже сохранять сюда. `422` — синхронизация уже идёт или копировать нечего |

Настройки S3 хранятся зашифрованными (`encrypted:array`), секретный ключ в ответах не отдаётся. Бекап сначала пишется на локальный диск (поэтому проверка свободного места — всегда по нему), после успешного снятия копия раскладывается по всем выбранным местам: в бакеты загружается, на диске остаётся, только если он выбран. У бекапа — `copies` (`storage_id`, `name`, `type`, `status`, `error`); бекап удачный, если сохранился хотя бы в одно место, неудачные копии — предупреждением в журнале. Скачивание — из удачной копии (диск в приоритете) или `?storage_id=`. Скачивание из S3 идёт потоком через SystemKeeper.

Настройки хранения задаются у каждого источника — в `PUT /servers/{id}/backup-settings` и `PATCH /databases/{id}`, в ответе — объект `retention`:

- `storage_ids` — куда сохранять, можно несколько мест (пусто — в место по умолчанию);
- `keep_count` — сколько последних успешных бекапов хранить (1–1000, по умолчанию 7);
- `max_size` — лимит объёма всех бекапов источника, байт (от 1 МБ; `null` — без лимита).

После каждого бекапа лишние удаляются: сначала сверх `keep_count`, затем самые старые, пока объём больше `max_size`. Самый новый успешный бекап не удаляется никогда — если он один больше лимита, в журнале будет предупреждение.


Синхронизация копирует каждый бекап из хранилища, где он уже лежит (сначала с диска SystemKeeper), сверяет размер файлов, кладёт `backup.json` и обновляет оглавление (`index.json`, `README.md`). Ошибка одного бекапа не останавливает остальные: у него появляется неудачная копия, а повторный запуск докопирует только недостающее.

## API: версия и обновление

- `GET /system/version` — `{current, latest: {version, name, notes, url, published_at} | null, update_available, checked_at, check_error, updater: {available, status, message, from, to, updated_at}}`. `updater.available` — запущен ли контейнер `updater`; `status`: `idle`, `pending`, `waiting` (ждёт бекапы), `backup`, `pulling`, `restarting`, `done`, `failed`, `rolled_back`.
- `POST /system/check` — проверить релизы сейчас (администратор, не чаще 10 раз в минуту).
- `POST /system/update` — обновиться до последней версии (администратор), `202`; `422`, если обновлений нет, обновлятор не запущен или обновление уже идёт.
