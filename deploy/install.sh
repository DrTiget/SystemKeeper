#!/bin/sh
# Установка SystemKeeper одной командой:
#   curl -fsSL https://github.com/DrTiget/SystemKeeper/releases/latest/download/install.sh | sudo sh
# Повторный запуск безопасен: .env и данные сохраняются, файлы установки обновляются до выбранной версии.
# Переменные: SK_DIR (/opt/systemkeeper), SK_PORT (8080), SK_VERSION (последний релиз), SK_ADMIN_EMAIL.
set -eu

REPO="${SK_REPO:-DrTiget/SystemKeeper}"
DIR="${SK_DIR:-/opt/systemkeeper}"
PORT="${SK_PORT:-8080}"

say() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mОшибка:\033[0m %s\n' "$*" >&2; exit 1; }
random() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c "${1:-32}"; }

[ "$(id -u)" -eq 0 ] || die "запустите от root: curl -fsSL … | sudo sh"
command -v curl >/dev/null || die "нужен curl"

if ! command -v docker >/dev/null; then
    say "Ставлю Docker"
    curl -fsSL https://get.docker.com | sh
fi
docker compose version >/dev/null 2>&1 || die "нужен Docker Compose v2 (docker compose)"

VERSION="${SK_VERSION:-}"
if [ -z "$VERSION" ]; then
    VERSION=$(curl -fsSLI -o /dev/null -w '%{url_effective}' "https://github.com/$REPO/releases/latest" | sed 's|.*/tag/||')
    echo "$VERSION" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$' || die "не удалось узнать последнюю версию"
fi
say "Версия $VERSION → $DIR"

mkdir -p "$DIR" && cd "$DIR"
for f in compose.yaml updater.sh; do
    curl -fsSL -o "$f" "https://github.com/$REPO/releases/download/$VERSION/$f" || die "не удалось скачать $f"
done

if [ ! -f .env ]; then
    ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    cat > .env <<ENV
# SystemKeeper. APP_KEY шифрует пароли, ключи SSH и доступы к облакам в базе — без него их не расшифровать:
# сохраните копию этого файла в надёжном месте.
APP_NAME=SystemKeeper
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_URL=http://${ip:-localhost}:$PORT
APP_PORT=$PORT
APP_LOCALE=ru
SK_DIR=$DIR
SK_VERSION=$VERSION

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=systemkeeper
DB_USERNAME=systemkeeper
DB_PASSWORD=$(random 32)
DB_ROOT_PASSWORD=$(random 32)

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
ENV
    chmod 600 .env
    say "Создан $DIR/.env — сохраните его копию (в нём APP_KEY)"
else
    sed -i "s|^SK_VERSION=.*|SK_VERSION=$VERSION|" .env
    grep -q '^SK_DIR=' .env || echo "SK_DIR=$DIR" >> .env
fi

mkdir -p data/storage data/mysql state backups
chown 33:33 data/storage state

say "Скачиваю образы"
docker compose pull --quiet
docker compose up -d --remove-orphans

say "Жду запуска"
i=0
until [ "$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q web)" 2>/dev/null)" = healthy ]; do
    i=$((i + 1)); [ $i -gt 90 ] && die "не запустилось за 7 минут — смотрите: cd $DIR && docker compose logs web"
    sleep 5
done

cat > /usr/local/bin/systemkeeper <<CLI
#!/bin/sh
# Управление SystemKeeper: systemkeeper update [vX.Y.Z] | status | logs [сервис] | admin | down | up
cd "$DIR" || exit 1
case "\${1:-}" in
    update)
        v="\${2:-\$(curl -fsSLI -o /dev/null -w '%{url_effective}' https://github.com/$REPO/releases/latest | sed 's|.*/tag/||')}"
        printf '{"version":"%s"}\n' "\$v" > state/request.json
        echo "Запрошено обновление до \$v"
        last=
        while sleep 3; do
            s=\$(cat state/status.json 2>/dev/null)
            [ "\$s" != "\$last" ] && echo "\$s" && last="\$s"
            [ -f state/request.json ] && continue
            case "\$s" in *'"done"'*|*'"failed"'*|*'"rolled_back"'*) break ;; esac
        done
        ;;
    status) docker compose ps; cat state/status.json 2>/dev/null ;;
    logs) shift; docker compose logs -f --tail=100 "\$@" ;;
    admin) docker compose exec -u www-data web php artisan user:create ;;
    down) docker compose down ;;
    up) docker compose up -d ;;
    *) echo "systemkeeper update [vX.Y.Z] | status | logs [сервис] | admin | down | up" ;;
esac
CLI
chmod +x /usr/local/bin/systemkeeper

url=$(sed -n 's/^APP_URL=//p' .env)
users=$(docker compose exec -T mysql sh -c 'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM users"' 2>/dev/null | tail -n1)
if [ "${users:-0}" = 0 ]; then
    email="${SK_ADMIN_EMAIL:-}"
    if [ -z "$email" ] && [ -r /dev/tty ]; then
        printf 'Email администратора: ' >/dev/tty; read -r email </dev/tty || true
    fi
    if [ -n "$email" ]; then
        password=$(random 16)
        docker compose exec -T -u www-data web php artisan user:create --name=Admin --email="$email" --password="$password" >/dev/null \
            && say "Администратор: $email, пароль: $password (смените его в профиле)"
    else
        say "Создайте администратора: systemkeeper admin"
    fi
fi

say "Готово: $url"
say "Обновления — кнопкой в интерфейсе или командой: systemkeeper update"
