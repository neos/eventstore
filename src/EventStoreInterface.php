<?php
declare(strict_types=1);
namespace Neos\EventStore;

use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStore\CommitResult;
use Neos\EventStore\Model\EventStream\EventStreamFilter;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Neos\EventStore\Model\Events;

/**
 * Common interface for an event store backend
 * @api
 */
interface EventStoreInterface
{
    /**
     * Load events from the specified stream (or virtual stream) in the order they were persisted, optionally applying a filter
     *
     * @param StreamName|VirtualStreamName $streamName The stream or virtual stream to fetch events from
     * @param EventStreamFilter|null $filter Optional filter that allows to skip certain events
     * @return EventStreamInterface The resulting event stream that can be iterated
     */
    public function load(StreamName|VirtualStreamName $streamName, EventStreamFilter $filter = null): EventStreamInterface;

    /**
     * Append one or more events to the specified stream
     *
     * @param StreamName $streamName Name of the stream to append the event(s) to
     * @param Events $events The events to append to the stream
     * @param ExpectedVersion $expectedVersion The expected {@see Version} of the last event in the specified stream
     * @return CommitResult The result of this call that contains information about the committed {@see Version} and {@see SequenceNumber}
     * @throws ConcurrencyException in case that the $expectedVersion check fails
     */
    public function commit(StreamName $streamName, Events $events, ExpectedVersion $expectedVersion): CommitResult;

    /**
     * Permanently remove all events from the specified stream
     * Note: Not all implementations might support this!
     *
     * @param StreamName $streamName Name of the stream to prune
     */
    public function deleteStream(StreamName $streamName): void;
}
