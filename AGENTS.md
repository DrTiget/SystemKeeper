# SystemKeeper — правила для ИИ-агентов

Сервис бекапов и мониторинга (см. README.md). Laravel 13, PHP 8.5, MySQL 8.4, очереди `database`, API-only (`/api/v1`).

## Коммиты

- Сообщения коммитов пишутся **на русском языке**: краткий заголовок в повелительном наклонении («Добавь…», «Исправь…»), при необходимости — описание после пустой строки.
- ИИ **не указывается** автором или соавтором: никаких строк `Co-Authored-By: Claude …`, `Co-authored-by: Copilot …`, «Generated with Claude Code» и подобных — ни в коммитах, ни в описаниях PR.
- Автор коммита — пользователь из `git config`; не меняй его.

## Локальная разработка (Docker)

Сервисы `compose.yaml`: `app` (php-fpm 8.5), `nginx` (порт `APP_PORT`, по умолчанию 8080), `mysql`, `queue` (`queue:work`), `scheduler` (`schedule:work`).

```sh
cp .env.example .env
UID=$(id -u) GID=$(id -g) docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Все команды PHP/Composer/Artisan выполняй через `docker compose exec app …`.

## Тесты

```sh
docker compose exec app php artisan test
```

Тесты используют отдельную БД `systemkeeper_testing` (создаётся при инициализации MySQL, `docker/mysql/init`). Каждое изменение сопровождай тестами; перед коммитом тесты должны проходить.

## Код

- Следуй соглашениям Laravel и стилю окружающего кода; форматирование — `docker compose exec app ./vendor/bin/pint`.
- API — только JSON под `/api/v1`; web-маршруты не развиваем.
- Секреты (SSH-ключи, токены Яндекс.Диска) не коммитятся и не пишутся в логи.
