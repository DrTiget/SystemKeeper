# Поиск того, что стоит бекапить на сервере. Только чтение, без sudo; что недоступно — пропускается.
# Вывод — строки через табуляцию:
#   P  путь  категория  почему         — каталог-кандидат
#   D  СУБД  где        подробности    — найденная СУБД (local или docker:имя)
#   X  маска почему                    — что стоит исключить
#   I  ключ  значение                  — сведения о сервере
LC_ALL=C
export LC_ALL
t=$(printf '\t')
p() { [ -e "$1" ] && printf 'P\t%s\t%s\t%s\n' "$1" "$2" "$3"; }
d() { printf 'D\t%s\t%s\t%s\n' "$1" "$2" "$3"; }
x() { printf 'X\t%s\t%s\n' "$1" "$2"; }
has() { command -v "$1" >/dev/null 2>&1; }
nonempty() { [ -d "$1" ] && [ -n "$(ls -A "$1" 2>/dev/null | head -n 1)" ]; }

printf 'I\thostname\t%s\n' "$(hostname 2>/dev/null)"
printf 'I\tuser\t%s\n' "$(id -un 2>/dev/null)"
[ -r /etc/os-release ] && printf 'I\tos\t%s\n' "$(. /etc/os-release; echo "$PRETTY_NAME")"

# Конфигурация системы и сервисов
p /etc system 'Настройки системы и сервисов: nginx, cron, systemd, SSH и т. д.'
nonempty /var/spool/cron && p /var/spool/cron system 'Задания cron пользователей'
nonempty /usr/local/bin && p /usr/local/bin system 'Свои скрипты и программы'

# Сайты и приложения
for dir in /var/www /srv /opt /home /root /var/opt; do
    nonempty "$dir" || continue
    case $dir in
        /var/www) why='Сайты' ;;
        /srv) why='Данные сервисов и сайтов' ;;
        /opt|/var/opt) why='Установленные приложения' ;;
        /home) why='Домашние каталоги пользователей' ;;
        /root) why='Домашний каталог root: скрипты, ключи, настройки' ;;
    esac
    p "$dir" apps "$why"
done

# Корни сайтов из настроек веб-серверов
for f in $(ls /etc/nginx/nginx.conf /etc/nginx/conf.d/*.conf /etc/nginx/sites-enabled/* 2>/dev/null); do
    sed -n 's/^[[:space:]]*root[[:space:]]\{1,\}\([^;[:space:]]*\).*/\1/p' "$f" 2>/dev/null
done | sort -u | while read -r r; do p "$r" web "Корень сайта в nginx"; done
for f in $(ls /etc/apache2/sites-enabled/* /etc/httpd/conf.d/*.conf /etc/httpd/conf/httpd.conf 2>/dev/null); do
    sed -n 's/^[[:space:]]*DocumentRoot[[:space:]]\{1,\}"\{0,1\}\([^"[:space:]]*\).*/\1/p' "$f" 2>/dev/null
done | sort -u | while read -r r; do p "$r" web "Корень сайта в Apache"; done

# Почта, сертификаты
for dir in /var/mail /var/vmail /var/spool/mail; do nonempty "$dir" && p "$dir" mail 'Почтовые ящики'; done
nonempty /etc/letsencrypt && p /etc/letsencrypt web 'Сертификаты Let’s Encrypt'

# Docker: каталоги compose-проектов, тома, СУБД в контейнерах
if has docker && docker info >/dev/null 2>&1; then
    printf 'I\tdocker\t%s\n' "$(docker version --format '{{.Server.Version}}' 2>/dev/null)"
    docker ps --format '{{.Label "com.docker.compose.project.working_dir"}}' 2>/dev/null | sort -u | while read -r w; do
        [ -n "$w" ] && p "$w" docker 'Каталог docker compose проекта: compose.yaml, .env, данные в каталоге'
    done
    docker ps --format "{{.Names}}${t}{{.Image}}" 2>/dev/null | while IFS="$t" read -r name image; do
        case $image in
            *mariadb*|*mysql*|*percona*) d mysql "docker:$name" "$image" ;;
            *postgres*|*postgis*|*timescale*) d postgres "docker:$name" "$image" ;;
            *clickhouse*) d clickhouse "docker:$name" "$image" ;;
            *mongo*) d mongodb "docker:$name" "$image" ;;
            *redis*|*valkey*|*keydb*) d redis "docker:$name" "$image" ;;
        esac
    done
    root=$(docker info --format '{{.DockerRootDir}}' 2>/dev/null)
    [ -n "$root" ] && nonempty "$root/volumes" && p "$root/volumes" docker 'Именованные тома Docker. Тома с базами копировать файлами не стоит — дампы надёжнее'
elif has docker; then
    printf 'I\tdocker\t%s\n' 'нет доступа к docker (нужны права root или группа docker)'
fi

# СУБД, установленные на самом сервере
running() { ps -eo comm= 2>/dev/null | grep -qx "$1"; }
{ running mysqld || running mariadbd; } && d mysql local "$( (mysqld --version || mariadbd --version) 2>/dev/null | head -n 1)"
running postgres && d postgres local "$(postgres --version 2>/dev/null || psql --version 2>/dev/null)"
running clickhouse-server && d clickhouse local "$(clickhouse-server --version 2>/dev/null | head -n 1)"
running mongod && d mongodb local "$(mongod --version 2>/dev/null | head -n 1)"
running redis-server && d redis local "$(redis-server --version 2>/dev/null)"

# Что исключить
x 'node_modules' 'Зависимости Node.js — восстанавливаются npm install'
x '.cache' 'Кеши программ'
x '*.log' 'Журналы'
x '*.tmp' 'Временные файлы'
x '/var/www/*/storage/framework/cache' 'Кеш Laravel'
x '/var/www/*/var/cache' 'Кеш Symfony'
for dir in /var/lib/mysql /var/lib/postgresql /var/lib/clickhouse /var/lib/mongodb; do
    [ -d "$dir" ] && x "$dir" 'Файлы работающей СУБД копировать нельзя — бекапьте её дампом'
done
exit 0
