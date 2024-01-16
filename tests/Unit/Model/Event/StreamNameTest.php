<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit\Model\Event;

use InvalidArgumentException;
use Neos\EventStore\Model\Event\StreamName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamName::class)]
final class StreamNameTest extends TestCase
{

    public static function dataProvider_invalid_strings(): iterable
    {
        yield 'empty' => [''];
        yield 'non-ascii' => ['not-än-ascii'];
        yield 'too-long' => ['this-string-is-too-long-to-represent-a-valid-stream-name-this-string-is-too-long-to-represent-a-valid-stream-name'];
    }

    #[DataProvider('dataProvider_invalid_strings')]
    public function test_fromString_with_invalid_strings(string $string): void
    {
        $this->expectException(InvalidArgumentException::class);
        StreamName::fromString($string);
    }

    public static function dataProvider_valid_strings(): iterable
    {
        yield ['this-is-a-valid-event-type'];
        yield ['Event Types can contain whitespace'];
    }

    #[DataProvider('dataProvider_valid_strings')]
    public function test_fromString_with_valid_strings(string $string): void
    {
        self::assertSame($string, StreamName::fromString($string)->value);
    }
}