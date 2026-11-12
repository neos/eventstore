<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit\Model\EventStream;

use Neos\EventStore\Model\Event\CorrelationId;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Neos\EventStore\Model\EventStream\VirtualStreamType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VirtualStreamName::class)]
final class VirtualStreamNameTest extends TestCase
{
    public function test_forCorrelationId(): void
    {
        $streamName = VirtualStreamName::forCorrelationId(CorrelationId::fromString('some-correlation-id'));

        self::assertSame(VirtualStreamType::CORRELATION_ID, $streamName->type);
        self::assertSame('some-correlation-id', $streamName->value);
    }

    public function test_forCategory(): void
    {
        $streamName = VirtualStreamName::forCategory('some-category');

        self::assertSame(VirtualStreamType::CATEGORY, $streamName->type);
        self::assertSame('some-category', $streamName->value);
    }
}
