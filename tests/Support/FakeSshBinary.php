<?php

namespace Tests\Support;

/**
 * Поддельный бинарник `ssh` для тестов драйвера openssh: пишет в $dir/log аргументы (по одному на строку),
 * копирует переданный через -i ключ (с правами) и known_hosts, затем ведёт себя согласно $mode.
 */
class FakeSshBinary
{
    public string $dir;

    public string $path;

    public function __construct(string $mode = 'ok')
    {
        $this->dir = sys_get_temp_dir().'/sk-fake-ssh-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->path = $this->dir.'/ssh';
        $log = $this->dir.'/log';

        file_put_contents($this->path, <<<BASH
        #!/bin/bash
        printf '%s\n' "\$@" > {$log}
        echo \$\$ > {$this->dir}/pid
        printf '%s' "\$SK_SSH_PASSWORD" > {$this->dir}/password_env
        prev=""
        for a in "\$@"; do
          if [ "\$prev" = "-i" ]; then
            cp "\$a" {$this->dir}/key; stat -c '%a' "\$a" > {$this->dir}/key_mode; echo "\$a" > {$this->dir}/key_path
          fi
          case "\$a" in UserKnownHostsFile=*) cp "\${a#UserKnownHostsFile=}" {$this->dir}/known_hosts; echo "\${a#UserKnownHostsFile=}" > {$this->dir}/kh_path;; esac
          prev="\$a"
        done
        if [ -n "\$SSH_ASKPASS" ]; then cp "\$SSH_ASKPASS" {$this->dir}/askpass; stat -c '%a' "\$SSH_ASKPASS" > {$this->dir}/askpass_mode; "\$SSH_ASKPASS" > {$this->dir}/askpass_out; fi
        case "$mode" in
          ok) printf 'chunk1-'; printf 'chunk2'; echo 'warn' >&2; exit 0 ;;
          exit3) printf 'partial'; echo 'remote failed' >&2; exit 3 ;;
          fail255) echo 'Permission denied (publickey).' >&2; exit 255 ;;
          big) head -c 5000000 /dev/zero; exit 0 ;;
          sleep) printf 'x'; sleep 30; exit 0 ;;
        esac
        BASH);
        chmod($this->path, 0755);
    }

    public function log(): array
    {
        return is_file($this->dir.'/log') ? (file($this->dir.'/log', FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    public function read(string $name): ?string
    {
        $f = "{$this->dir}/{$name}";

        return is_file($f) ? file_get_contents($f) : null;
    }

    public function __destruct()
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
}
