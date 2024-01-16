<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit\Model\Event;

use InvalidArgumentException;
use Neos\EventStore\Model\Event\EventId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventId::class)]
final class EventIdTest extends TestCase
{

    public static function dataProvider_invalid_strings(): iterable
    {
        yield 'empty' => [''];
        yield 'invalid syntax' => ['not-a-uuid'];
        yield 'whitespace' => [' ebfeefdb-d4fe-4941-9589-e1239119f8b6 '];
    }

    #[DataProvider('dataProvider_invalid_strings')]
    public function test_fromString_with_invalid_strings(string $string): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventId::fromString($string);
    }

    public static function dataProvider_valid_strings(): iterable
    {
        yield ['ebfeefdb-d4fe-4941-9589-e1239119f8b6'];
        yield ['84f0668c-ca92-4c0e-8cdc-e7ba79c4545b'];
    }

    #[DataProvider('dataProvider_valid_strings')]
    public function test_fromString_with_valid_strings(string $string): void
    {
        self::assertSame($string, EventId::fromString($string)->value);
    }

    public function test_fromString_adds_dashes(): void
    {
        $input = '84f0668cca924c0e8cdce7ba79c4545b';
        $expectedResult = '84f0668c-ca92-4c0e-8cdc-e7ba79c4545b';
        self::assertSame($expectedResult, EventId::fromString($input)->value);
    }
}