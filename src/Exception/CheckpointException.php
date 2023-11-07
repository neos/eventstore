<?php
declare(strict_types=1);
namespace Neos\EventStore\Exception;

use Neos\EventStore\CatchUp\CheckpointStorageInterface;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * Exception that can occur when acquiring or updating a {@see SequenceNumber} via {@see CheckpointStorageInterface}
 * @api
 */
final class CheckpointException extends \RuntimeException
{
}
