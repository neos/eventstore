<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStore;

/**
 * The result of a {@see EventStoreInterface::status()} call
 * @api
 */
final class Status
{
    /**
     * @param StatusType $type The type of status
     * @param string $details Further technical details about the status
     */
    private function __construct(
        public readonly StatusType $type,
        public readonly string $details,
    ) {
    }
}
