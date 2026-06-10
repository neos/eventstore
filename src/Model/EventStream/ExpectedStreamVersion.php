<?php
declare(strict_types=1);

namespace Neos\EventStore\Model\EventStream;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;

final readonly class ExpectedStreamVersion
{
    private function __construct(
        public StreamName $streamName,
        public Version $expectedVersion
    ) {
    }

    public static function create(
        StreamName $streamName,
        Version $expectedVersion,
    ): self {
        return new self(
            streamName: $streamName,
            expectedVersion: $expectedVersion,
        );
    }

    public function isSatisfiedBy(MaybeVersion $version): bool
    {
        return !$version->isNothing()
            && $version->unwrap()->value === $this->expectedVersion->value;
    }

    public function toDebugString(): string
    {
        return sprintf('[%s equals %s]', $this->streamName->value, $this->expectedVersion->value);
    }
}
