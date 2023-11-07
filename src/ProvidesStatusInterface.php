<?php
declare(strict_types=1);
namespace Neos\EventStore;

use Neos\EventStore\Model\EventStore\Status;

/**
 * Common interface for classes that provide status information (usually implementations of {@see EventStoreInterface} and {@see CheckpointStorageInterface})
 * @api
 */
interface ProvidesStatusInterface
{
    public function status(): Status;
}
