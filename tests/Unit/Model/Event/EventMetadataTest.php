<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit\Model\Event;

use InvalidArgumentException;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventMetadata::class)]
final class EventMetadataTest extends TestCase
{

    public static function dataProvider_invalid_arrays(): iterable
    {
        yield 'simple array' => [[1, 2, 3]];
        yield 'non-associative array' => [[2 => 'foo', 3 => 'bar']];
    }

    #[DataProvider('dataProvider_invalid_arrays')]
    public function test_fromArray_with_invalid_arrays(array $array): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventMetadata::fromArray($array);
    }

    public static function dataProvider_valid_arrays(): iterable
    {
        yield 'empty array' => [[]];
        yield 'single item' => [['foo' => 'bar']];
    }

    #[DataProvider('dataProvider_valid_arrays')]
    public function test_fromArray_with_valid_arrays(array $array): void
    {
        self::assertSame($array, EventMetadata::fromArray($array)->value);
    }

    public static function dataProvider_invalid_json(): iterable
    {
        yield 'empty string' => [''];
        yield 'invalid JSON' => ['not json'];
        yield 'JSON string' => ['"foo"'];
        yield 'JSON bool' => ['true'];
    }

    #[DataProvider('dataProvider_invalid_json')]
    public function test_fromJson_with_invalid_strings(string $string): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventMetadata::fromJson($string);
    }

    public static function dataProvider_valid_json(): iterable
    {
        yield 'empty object' => ['{}', []];
        yield 'empty array' => ['[]', []];
        yield 'object with one key' => ['{"foo":"bar"}', ['foo' => 'bar']];
        yield 'object with two keys' => ['{"foo":"bar","baz":"foos"}', ['foo' => 'bar', 'baz' => 'foos']];
    }

    #[DataProvider('dataProvider_valid_json')]
    public function test_fromJson_with_valid_strings(string $string, array $expectedResult): void
    {
        self::assertSame($expectedResult, EventMetadata::fromJson($string)->value);
    }

    public function test_has_returns_false_if_key_is_not_set(): void
    {
        $metadata = EventMetadata::fromArray(['foo' => 'bar']);
        self::assertFalse($metadata->has('bar'));
    }

    public function test_has_returns_true_if_key_is_set(): void
    {
        $metadata = EventMetadata::fromArray(['foo' => 'bar']);
        self::assertTrue($metadata->has('foo'));
    }

    public function test_has_returns_true_if_key_is_set_and_value_is_null(): void
    {
        $metadata = EventMetadata::fromArray(['foo' => null]);
        self::assertTrue($metadata->has('foo'));
    }

    public function test_get_returns_null_if_key_is_not_set(): void
    {
        $metadata = EventMetadata::fromArray(['foo' => 'bar']);
        self::assertNull($metadata->get('bar'));
    }

    public function test_get_returns_value_if_key_is_set(): void
    {
        $metadata = EventMetadata::fromArray(['foo' => 'bar']);
        self::assertSame('bar', $metadata->get('foo'));
    }

    public function test_get_returns_true_if_key_is_set_and_value_is_null(): void
    {
        $metadata = EventMetadata::fromArray(['foo' => null]);
        self::assertNull($metadata->get('foo'));
    }
}