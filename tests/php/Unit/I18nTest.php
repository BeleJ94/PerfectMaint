<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\I18n;
use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testFrenchAndEnglishCataloguesHaveIdenticalKeys(): void
    {
        $fr = require self::root() . '/lang/fr.php';
        $en = require self::root() . '/lang/en.php';

        self::assertSame([], array_values(array_diff(array_keys($fr), array_keys($en))), 'Clés manquantes en anglais');
        self::assertSame([], array_values(array_diff(array_keys($en), array_keys($fr))), 'Clés absentes du français');
        foreach ([$fr, $en] as $catalogue) {
            foreach ($catalogue as $key => $text) {
                self::assertNotSame('', trim($text), "Traduction vide : $key");
            }
        }
    }

    public function testParametersArePreservedAcrossLanguages(): void
    {
        $fr = require self::root() . '/lang/fr.php';
        $en = require self::root() . '/lang/en.php';
        foreach ($fr as $key => $text) {
            preg_match_all('/:([a-z_]+)/', $text, $a);
            preg_match_all('/:([a-z_]+)/', $en[$key], $b);
            sort($a[1]);
            sort($b[1]);
            self::assertSame($a[1], $b[1], "Paramètres différents pour $key");
        }
    }

    /** Toute clé littérale utilisée dans le code (PHP et JS) doit exister. */
    public function testEveryKeyUsedInCodeExists(): void
    {
        $fr = require self::root() . '/lang/fr.php';
        $files = array_merge(
            iterator_to_array(new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/app')), '/\.php$/')),
            array_map(static fn ($f) => new \SplFileInfo($f), glob(self::root() . '/public/assets/js/*.js') ?: [])
        );
        $missing = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents((string) $file);
            preg_match_all("/\\bt\\('([a-z_]+\\.[a-z0-9_.]+)'/", $source, $m);
            preg_match_all("/'(?:labelKey|section)' => '([a-z_]+\\.[a-z0-9_.]+)'/", $source, $m2);
            preg_match_all("/\\['\\/[^']*', '[a-z0-9-]+', '([a-z_]+\\.[a-z0-9_.]+)'/", $source, $m3);
            foreach (array_merge($m[1], $m2[1], $m3[1]) as $key) {
                if (!isset($fr[$key])) {
                    $missing[] = $key . ' (' . basename((string) $file) . ')';
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
    }

    public function testEnumDerivedKeysExist(): void
    {
        $i18n = new I18n(self::root() . '/lang');
        foreach (\App\Domain\EquipmentStatus::cases() as $s) {
            self::assertTrue($i18n->has($s->labelKey()), $s->labelKey());
        }
        foreach (\App\Domain\WorkOrderStatus::cases() as $s) {
            self::assertTrue($i18n->has($s->labelKey()), $s->labelKey());
            self::assertTrue($i18n->has('transition.' . $s->value) || $s === \App\Domain\WorkOrderStatus::Open, 'transition.' . $s->value);
        }
        foreach (\App\Domain\Role::cases() as $r) {
            self::assertTrue($i18n->has($r->labelKey()), $r->labelKey());
        }
        foreach (\App\Controllers\ActivityController::ENTITY_TYPES as $type) {
            self::assertTrue($i18n->has('entity.' . $type), 'entity.' . $type);
        }
        foreach (\App\Controllers\ActivityController::ACTIONS as $action) {
            self::assertTrue($i18n->has('action.' . $action), 'action.' . $action);
        }
        foreach (['required', 'string', 'array', 'int', 'numeric', 'email', 'date', 'datetime', 'in', 'min_value', 'max_value', 'min_length', 'max_length'] as $rule) {
            self::assertTrue($i18n->has('validation.' . $rule), 'validation.' . $rule);
        }
    }

    public function testFallbackAndParameters(): void
    {
        $i18n = new I18n(self::root() . '/lang', 'en');
        self::assertSame('Dashboard', $i18n->get('nav.dashboard'));
        self::assertSame('3 preventive work order(s) created.', $i18n->get('preventive.generated', ['count' => 3]));
        self::assertSame('unknown.key', $i18n->get('unknown.key'));

        $i18n->setLocale('fr');
        self::assertSame('Tableau de bord', $i18n->get('nav.dashboard'));
    }

    public function testJsCatalogueOnlyExposesWhitelistedPrefixes(): void
    {
        $catalogue = (new I18n(self::root() . '/lang'))->jsCatalogue();
        self::assertArrayHasKey('js.dt.search', $catalogue);
        self::assertArrayHasKey('status.breakdown', $catalogue);
        self::assertArrayNotHasKey('auth.invalid', $catalogue);
    }
}
