<?php
declare(strict_types=1);
namespace Neos\EventStore;

/**
 * Permanently remove events
 * Note: Not all implementations might support this!
 * @api
 */
interface PrunableEventStoreInterface
{
    /**
     * Permanently remove all events from all streams.
     * The sequence number is reset.
     */
    public function prune(): void;
}
