<?php
namespace Neos\EventStore\Model\EventStore;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;

final readonly class VersionForStream
{
    private function __construct(
        public StreamName $streamName,
        public Version $version
    ) {
    }

    public static function create(
        StreamName $streamName,
        Version $version,
    ): self {
        return new self(
            streamName: $streamName,
            version: $version,
        );
    }
}
