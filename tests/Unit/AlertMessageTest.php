<?php

namespace Tests\Unit;

use App\Services\Alerts\AlertKinds;
use App\Services\Alerts\AlertMessage;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Формат уведомлений: заголовки и темы по статусам, экранирование пользовательских данных в HTML Telegram и Markdown.
 */
class AlertMessageTest extends TestCase
{
    private const EVIL = 'a<b>&"c" _x_ *y* [z](http://e) `k` #1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sk.example.org', 'backup.timezone' => 'UTC']);
        Carbon::setTestNow('2026-10-19 12:00:00');
    }

    private function message(string $event = 'firing', string $severity = 'critical', array $overrides = []): AlertMessage
    {
        return new AlertMessage(...$overrides + [
            'event' => $event,
            'severity' => $severity,
            'message' => 'CPU 97% дольше 5 мин',
            'objectType' => 'server',
            'objectName' => 'web-1',
            'project' => 'Магазин',
            'ruleName' => 'CPU высокий',
            'ruleUrl' => 'https://sk.example.org/alerts/rules/7',
            'condition' => 'CPU выше 90% дольше 5 мин',
            'startedAt' => now()->subMinutes(7),
            'resolvedAt' => $event === 'resolved' ? now() : null,
            'url' => 'https://sk.example.org/servers/3',
        ]);
    }

    public function test_subjects_and_headings_by_status(): void
    {
        $critical = $this->message();
        $this->assertSame('[КРИТИЧНО] Сервер web-1: CPU 97% дольше 5 мин', $critical->subject());
        $this->assertSame('Критично: Сервер web-1', $critical->heading());
        $this->assertSame('🔴', $critical->icon());
        $this->assertSame('critical', $critical->status());

        $warning = $this->message('firing', 'warning');
        $this->assertStringStartsWith('[ПРЕДУПРЕЖДЕНИЕ] ', $warning->subject());
        $this->assertSame('🟠', $warning->icon());

        $repeat = $this->message('repeat');
        $this->assertStringStartsWith('[КРИТИЧНО · НЕ РЕШЕНО] ', $repeat->subject());
        $this->assertSame('Не решено: Сервер web-1', $repeat->heading());

        $resolved = $this->message('resolved');
        $this->assertStringStartsWith('[РЕШЕНО] Сервер web-1: ', $resolved->subject());
        $this->assertSame('Решено: Сервер web-1', $resolved->heading());
        $this->assertSame('✅', $resolved->icon());
        $this->assertSame('resolved', $resolved->status());

        $test = $this->message('firing', 'critical', ['test' => true]);
        $this->assertStringStartsWith('[ТЕСТ] Сервер web-1: ', $test->subject());
        $this->assertSame('Тестовое уведомление', $test->heading());
        $this->assertSame('🧪', $test->icon());
        // цвет теста — по важности правила
        $this->assertSame($critical->color(), $test->color());
    }

    public function test_colors_differ_by_status(): void
    {
        $colors = [$this->message()->color(), $this->message('firing', 'warning')->color(), $this->message('resolved')->color(), AlertMessage::test('x')->color()];

        $this->assertCount(4, array_unique($colors));
    }

    public function test_rows_include_duration_for_repeat_and_resolved_only(): void
    {
        $labels = fn (AlertMessage $m) => array_column($m->rows(), 'label');

        $this->assertSame(['Объект', 'Проект', 'Правило', 'Условие', 'Важность', 'Начало'], $labels($this->message()));
        $this->assertContains('Длительность', $labels($this->message('repeat')));
        $this->assertContains('Решено', $labels($this->message('resolved')));
        $this->assertSame(['Объект', 'Проект', 'Правило', 'Начало', 'Длительность'], array_column($this->message('repeat')->rows(false), 'label'));

        $duration = collect($this->message('resolved')->rows())->firstWhere('label', 'Длительность');
        $this->assertSame('7 мин', $duration['value']);
        $this->assertNotContains('Длительность', $labels($this->message('firing', 'critical', ['test' => true])));
    }

    public function test_telegram_html_escapes_user_data(): void
    {
        $html = $this->message('firing', 'critical', ['objectName' => self::EVIL, 'ruleName' => self::EVIL, 'project' => self::EVIL, 'message' => self::EVIL])->telegramHtml();

        $escaped = 'a&lt;b&gt;&amp;"c" _x_ *y* [z](http://e) `k` #1';
        $this->assertStringNotContainsString('<b>a', $html);
        $this->assertStringNotContainsString('a<b>', $html);
        $this->assertSame(5, substr_count($html, $escaped));
        // все теги — только наши: <b>, <a href>
        $this->assertSame(['b', 'a'], array_values(array_unique($this->tags($html))));
        $this->assertStringContainsString('<a href="https://sk.example.org/servers/3">Открыть в SystemKeeper</a>', $html);
        $this->assertStringStartsWith('🔴 <b>Критично: Сервер '.$escaped.'</b>', $html);
    }

    /** @return list<string> имена тегов в порядке появления */
    private function tags(string $html): array
    {
        preg_match_all('#<(/?)([a-z]+)[ >]#', $html, $m);

        return array_values(array_filter($m[2], fn ($t, $i) => $m[1][$i] === '', ARRAY_FILTER_USE_BOTH));
    }

    public function test_telegram_html_escapes_quotes_in_link_attribute_and_limits_length(): void
    {
        $html = $this->message('firing', 'critical', ['ruleUrl' => 'https://x/?a="1"&b=<2>', 'message' => str_repeat('я', 6000), 'objectName' => str_repeat('<', 2000)])->telegramHtml();

        $this->assertStringContainsString('href="https://x/?a=&quot;1&quot;&amp;b=&lt;2&gt;"', $html);
        // лимит Telegram — 4096 знаков текста после разбора разметки
        $this->assertLessThan(4096, mb_strlen(html_entity_decode(strip_tags($html))));
        $this->assertStringNotContainsString('<<', $html);
    }

    public function test_markdown_escapes_user_data(): void
    {
        $md = $this->message('firing', 'critical', ['objectName' => self::EVIL, 'ruleName' => 'a_b *c* [d]', 'message' => self::EVIL])->markdown();

        $escaped = 'a\<b\>\&"c" \_x\_ \*y\* \[z\](http://e) \`k\` #1';
        $this->assertStringContainsString($escaped, $md);
        $this->assertStringContainsString('[a\_b \*c\* \[d\]](https://sk.example.org/alerts/rules/7)', $md);
        $this->assertStringContainsString('[Открыть в SystemKeeper](https://sk.example.org/servers/3)', $md);
        $this->assertStringStartsWith('**🔴 Критично: Сервер ', $md);
    }

    public function test_text_keeps_user_data_as_is(): void
    {
        $text = $this->message('firing', 'critical', ['objectName' => self::EVIL])->text();

        $this->assertStringStartsWith('🔴 Критично: Сервер '.self::EVIL."\nCPU 97% дольше 5 мин\n", $text);
        $this->assertStringContainsString('Объект: Сервер «'.self::EVIL.'»', $text);
        $this->assertStringContainsString('Открыть: https://sk.example.org/servers/3', $text);
    }

    public function test_test_notice_is_present_in_every_format(): void
    {
        $test = $this->message('firing', 'warning', ['test' => true]);

        foreach ([$test->text(), $test->markdown(), $test->telegramHtml()] as $text) {
            $this->assertStringContainsString('Тестовое уведомление', $text);
            $this->assertStringContainsString('реального сбоя нет', $text);
        }
        $this->assertSame('🧪 Тестовое уведомление: CPU 97% дольше 5 мин', $test->title());
    }

    public function test_without_app_url_there_are_no_links(): void
    {
        config(['app.url' => '']);

        $this->assertNull(AlertMessage::url('server', 1));
        $this->assertNull(AlertMessage::ruleUrl(1));
        $this->assertNull(AlertMessage::test('x')->url);
    }

    public function test_describe_condition_matches_frontend(): void
    {
        $this->assertSame('CPU выше 90% дольше 5 мин', AlertKinds::describe(AlertKinds::SERVER_CPU, 90, 5));
        $this->assertSame('CPU выше 90%', AlertKinds::describe(AlertKinds::SERVER_CPU, 90, 0));
        $this->assertSame('Диск заполнен больше 85%', AlertKinds::describe(AlertKinds::SERVER_DISK, 85, 30));
        $this->assertSame('Load на ядро больше 2,5 дольше 10 мин', AlertKinds::describe(AlertKinds::SERVER_LOAD, 2.5, 10));
        $this->assertSame('Осталось меньше 14 дн.', AlertKinds::describe(AlertKinds::MONITOR_SSL, 14, 0));
        $this->assertSame('Нет успешного бекапа дольше 26 ч', AlertKinds::describe(AlertKinds::BACKUP_MISSING, 26, 0));
        $this->assertSame('Сервер не отвечает дольше 3 мин', AlertKinds::describe(AlertKinds::SERVER_UNREACHABLE, null, 3));
        $this->assertSame('Бекап не удался', AlertKinds::describe(AlertKinds::BACKUP_FAILED, null, 0));
    }
}
