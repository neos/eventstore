<?php

declare(strict_types=1);

namespace Neos\EventStore\Model;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\ExpectedVersion;

final readonly class Commit
{
    public function __construct(
        public StreamName $streamName,
        public Events $events,
        public ExpectedVersion $expectedVersion,
    ) {
    }
}
