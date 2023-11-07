<?php
declare(strict_types=1);
namespace Neos\EventStore;

use Neos\EventStore\CatchUp\CheckpointStorageInterface;
use Neos\EventStore\Model\EventStore\SetupResult;

/**
 * Common interface for classes that require an initial setup (usually implementations of {@see EventStoreInterface} and {@see CheckpointStorageInterface})
 * @api
 */
interface ProvidesSetupInterface
{
    public function setup(): SetupResult;
}
