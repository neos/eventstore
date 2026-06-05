<?php
declare(strict_types=1);
namespace Neos\EventStore;

use Neos\EventStore\Model\Event\StreamName;

/**
 * Permanently remove events
 * Note: Not all implementations might support this!
 * @api
 */
interface WithResetInterface
{
    /**
     * Permanently remove all events from the specified stream
     *
     * @param StreamName $streamName Name of the stream to prune
     */
    public function deleteStream(StreamName $streamName): void;

    /**
     * Permanently remove all events from all streams.
     * The sequence number is reset.
     */
    public function reset(): void;
}
