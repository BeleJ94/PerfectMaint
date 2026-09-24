<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\I18n;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::setCurrent(new I18n(dirname(__DIR__, 3) . '/lang'));
    }

    public function testEscapesHtmlAndAttributes(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        self::assertSame('l&apos;engin', e("l'engin"));
        self::assertSame('', e(null));
        self::assertSame('12', e(12));
    }

    public function testJsonForHtmlCannotBreakOutOfScriptBlock(): void
    {
        $json = json_for_html(['x' => '</script><script>alert(1)</script>']);
        self::assertStringNotContainsString('</script>', $json);
        self::assertSame(['x' => '</script><script>alert(1)</script>'], json_decode($json, true));
    }

    public function testBadgesAreEscapedAndTranslated(): void
    {
        self::assertSame('<span class="badge badge--breakdown">En panne</span>', status_badge('breakdown'));
        self::assertStringContainsString('&lt;x&gt;', status_badge('<x>'));
        self::assertStringContainsString('badge--maintenance', wo_status_badge('in_progress'));
        self::assertStringContainsString('badge--neutral', priority_badge('low'));
    }

    public function testFrenchNumberAndDateFormats(): void
    {
        self::assertSame('2,5', format_qty('2.50'));
        self::assertSame('3', format_qty('3.00'));
        self::assertSame("1\u{202F}234,50", format_number(1234.5, 2));
        self::assertSame('24/09/2026', format_date('2026-09-24'));
        self::assertSame('24/09/2026 08:05', format_date('2026-09-24 08:05:00', true));
    }

    public function testNavCurrentMatchesSectionPrefix(): void
    {
        self::assertSame(' aria-current="page"', nav_current('/equipment/12', '/equipment'));
        self::assertSame('', nav_current('/equipment-types', '/equipment'));
        self::assertSame('', nav_current('/parts', '/'));
    }
}
