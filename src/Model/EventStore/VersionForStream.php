<?php
namespace Neos\EventStore\Model\EventStore;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;

final class VersionForStream
{
    public function __construct(
        public readonly StreamName $streamName,
        public readonly Version $version
    ) {}
}
