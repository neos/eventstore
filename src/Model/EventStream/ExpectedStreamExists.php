<?php
declare(strict_types=1);

namespace Neos\EventStore\Model\EventStream;

use Neos\EventStore\Model\Event\StreamName;

final readonly class ExpectedStreamExists
{
    private function __construct(
        public StreamName $streamName
    ) {
    }

    public static function create(
        StreamName $streamName
    ): self {
        return new self(
            streamName: $streamName
        );
    }

    public function isSatisfiedBy(MaybeVersion $version): bool
    {
        return !$version->isNothing();
    }

    public function toDebugString(): string
    {
        return sprintf('[exist %s]', $this->streamName->value);
    }
}
