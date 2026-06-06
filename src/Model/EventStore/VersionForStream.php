<?php
namespace Neos\EventStore\Model\EventStore;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;

final readonly class VersionForStream
{
    public function __construct(
        public StreamName $streamName,
        public Version $version
    ) {
    }
}
