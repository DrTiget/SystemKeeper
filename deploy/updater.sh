#!/bin/sh
# Обновлятор SystemKeeper. Работает в контейнере updater (docker cli + compose), каталог установки
# смонтирован по тому же пути, что и на хосте. Ждёт ./state/request.json ({"version":"v1.2.3"}),
# пишет ход обновления в ./state/status.json, журнал — в ./state/update.log.
#   sh updater.sh        — ждать запросы (режим контейнера)
#   sh updater.sh once   — выполнить запрос, если он есть, и выйти
set -u
cd "${SK_DIR:-/opt/systemkeeper}"

REPO="${SK_REPO:-DrTiget/SystemKeeper}"
STATE=state
mkdir -p "$STATE" backups

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
log() { echo "[$(now)] $*" | tee -a "$STATE/update.log"; }
json_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr '\n' ' '; }

# status <этап> <сообщение>: idle | pending | waiting | backup | pulling | restarting | done | failed | rolled_back
status() {
    printf '{"status":"%s","message":"%s","from":"%s","to":"%s","updated_at":"%s"}\n' \
        "$1" "$(json_escape "$2")" "${FROM:-}" "${TO:-}" "$(now)" > "$STATE/status.json.tmp"
    mv "$STATE/status.json.tmp" "$STATE/status.json"
    chmod 644 "$STATE/status.json"
    log "$1: $2"
}

env_get() { sed -n "s/^$1=//p" .env | tail -n1 | tr -d '"'; }
env_set() {
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}

app_services() { docker compose config --services | grep -vx updater | tr '\n' ' '; }

wait_healthy() {
    i=0
    while [ $i -lt 60 ]; do
        id=$(docker compose ps -q web)
        [ -n "$id" ] && [ "$(docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null)" = healthy ] && return 0
        sleep 5; i=$((i + 1))
    done
    return 1
}

db() { docker compose exec -T mysql sh -c "$1"; }

rollback() {
    status failed "$1 — откатываюсь на $FROM"
    cp compose.yaml.prev compose.yaml
    env_set SK_VERSION "$FROM"
    if [ -n "${DUMP:-}" ] && [ -s "$DUMP" ]; then
        docker compose stop web queue backups scheduler >/dev/null 2>&1
        db 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\`"' \
            && gunzip -c "$DUMP" | db 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
            || log "не удалось восстановить базу из $DUMP — восстановите вручную"
    fi
    docker compose up -d $(app_services) >>"$STATE/update.log" 2>&1
    if wait_healthy; then
        status rolled_back "$1. Возвращена версия $FROM, база восстановлена из дампа"
    else
        status failed "$1. Откат на $FROM тоже не поднялся — смотрите docker compose logs web"
    fi
}

update() {
    TO=$(sed -n 's/.*"version" *: *"\([^"]*\)".*/\1/p' "$STATE/request.json")
    rm -f "$STATE/request.json"
    FROM=$(env_get SK_VERSION)
    DUMP=
    status pending "Запрошено обновление до $TO"

    if ! echo "$TO" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$'; then
        status failed "Неверная версия «$TO»"; return
    fi
    if [ "$TO" = "$FROM" ]; then
        status done "Уже установлена версия $TO"; return
    fi

    base="${SK_RELEASES_URL:-https://github.com/$REPO/releases/download}/$TO"
    rm -rf "$STATE/next" && mkdir -p "$STATE/next"
    if ! wget -q -O "$STATE/next/compose.yaml" "$base/compose.yaml" || ! wget -q -O "$STATE/next/updater.sh" "$base/updater.sh"; then
        status failed "Не удалось скачать файлы релиза $TO"; return
    fi

    # Длинный бекап прерывать нельзя — ждём, пока воркер освободится (до 6 часов)
    i=0
    until [ -z "$(docker compose ps -q --status running backups)" ] \
        || docker compose exec -T -u www-data backups php artisan system:idle >/dev/null 2>&1; do
        [ $i -eq 0 ] && status waiting "Жду окончания текущих бекапов"
        i=$((i + 1))
        if [ $i -gt 2160 ]; then status failed "Бекапы не закончились за 6 часов — обновление отменено"; return; fi
        sleep 10
    done

    status backup "Дамп базы перед обновлением"
    DUMP="backups/pre-update-$FROM-$(date -u +%Y%m%d-%H%M%S).sql.gz"
    (umask 077 && : > "$DUMP")
    if ! db 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' | gzip > "$DUMP"; then
        rm -f "$DUMP"; status failed "Не удалось сделать дамп базы — обновление отменено"; return
    fi
    ls -1t backups/pre-update-*.sql.gz 2>/dev/null | tail -n +4 | xargs -r rm -f

    status pulling "Скачиваю версию $TO"
    cp compose.yaml compose.yaml.prev
    cp "$STATE/next/compose.yaml" compose.yaml
    env_set SK_VERSION "$TO"
    if ! docker compose pull --quiet $(app_services) >>"$STATE/update.log" 2>&1; then
        DUMP=; rollback "Не удалось скачать образ $TO"; return
    fi

    status restarting "Перезапускаю сервисы"
    # up -d сам ждёт здоровья web (depends_on); не поднялся — сразу откат
    if ! docker compose up -d --remove-orphans $(app_services) >>"$STATE/update.log" 2>&1 || ! wait_healthy; then
        rollback "Версия $TO не запустилась"; return
    fi

    status done "Обновлено до $TO"
    docker image prune -f >/dev/null 2>&1

    # Новый updater.sh: выходим, restart: unless-stopped запустит контейнер уже с ним
    if ! cmp -s "$STATE/next/updater.sh" updater.sh; then
        cp "$STATE/next/updater.sh" updater.sh.new && mv updater.sh.new updater.sh
        log "updater.sh обновлён, перезапускаюсь"
        exit 0
    fi
}

[ -f "$STATE/status.json" ] || status idle "Обновлений не запрашивали"

while true; do
    touch "$STATE/heartbeat"
    [ -f "$STATE/request.json" ] && update
    [ "${1:-}" = once ] && exit 0
    sleep 5
done
