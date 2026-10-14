<?php

namespace App\Support;

/** Размеры в человекочитаемом виде — для журналов и сообщений. */
final class Bytes
{
    public static function format(int $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : str_replace('.', ',', rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.'))).' '.$units[$i];
    }
}
