<?php
declare(strict_types=1);
namespace Neos\EventStore;

use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\EventStore\CommitResult;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Neos\EventStore\Model\Events;

interface EventStoreInterface
{
    public function load(StreamName|VirtualStreamName $streamName): EventStreamInterface;

    /**
     * @param StreamName $streamName
     * @param Events $events
     * @param ExpectedVersion $expectedVersion
     * @return CommitResult
     * @throws ConcurrencyException in case the expectedVersion does not match
     */
    public function commit(StreamName $streamName, Events $events, ExpectedVersion $expectedVersion): CommitResult;
    public function deleteStream(StreamName $streamName): void;
}
