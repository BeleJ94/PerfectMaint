<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\FuelGauge;
use PHPUnit\Framework\TestCase;

/** Lecture du carburant sur l'indicateur : graduation → litres et pourcentage. */
final class FuelGaugeTest extends TestCase
{
    public function testMarkIsConvertedToLitersAndPercent(): void
    {
        $gauge = new FuelGauge(FuelGauge::MARKS, 4, 80);
        self::assertSame(['fuel_level' => 50, 'fuel_mark' => 2.0, 'fuel_marks' => 4, 'fuel_liters' => 40.0], $gauge->fromMark(2));
        self::assertSame(30.0, $gauge->fromMark(1.5)['fuel_liters'], 'Demi-graduation');
        self::assertSame([0, 0.0], [$gauge->fromMark(0)['fuel_level'], $gauge->fromMark(0)['fuel_liters']]);
        self::assertSame([100, 80.0], [$gauge->fromMark(4)['fuel_level'], $gauge->fromMark(4)['fuel_liters']]);
    }

    public function testMarksOutsideTheGaugeAreRejected(): void
    {
        $gauge = new FuelGauge(FuelGauge::MARKS, 4, 80);
        self::assertFalse($gauge->acceptsMark(4.5));
        self::assertFalse($gauge->acceptsMark(1.3), 'Seules les demi-graduations sont admises');
        self::assertFalse($gauge->acceptsMark(-1));
        $this->expectException(\InvalidArgumentException::class);
        $gauge->fromMark(5);
    }

    public function testLitersNeedTheTankCapacity(): void
    {
        self::assertNull((new FuelGauge(FuelGauge::MARKS, 8, null))->fromMark(4)['fuel_liters']);
        self::assertSame(50, (new FuelGauge(FuelGauge::MARKS, 8, null))->fromMark(4)['fuel_level']);
        self::assertSame(60.0, (new FuelGauge(FuelGauge::PERCENT, null, 200))->fromPercent(30)['fuel_liters'], 'Indicateur numérique');
        self::assertFalse(FuelGauge::unconfigured()->usesMarks());
    }
}
