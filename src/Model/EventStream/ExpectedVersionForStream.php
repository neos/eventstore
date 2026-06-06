<?php
declare(strict_types=1);

namespace Neos\EventStore\Model\EventStream;

use Neos\EventStore\Model\Event\StreamName;

final readonly class ExpectedVersionForStream
{
    private function __construct(
        public StreamName $streamName,
        public ExpectedVersion $expectedVersion
    ) {
    }

    public static function create(
        StreamName $streamName,
        ExpectedVersion $expectedVersion,
    ): self {
        return new self(
            streamName: $streamName,
            expectedVersion: $expectedVersion,
        );
    }
}
