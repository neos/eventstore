<?php
declare(strict_types=1);
namespace Neos\EventStore\Exception;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\EventStream\ExpectedVersion;

/**
 * Exception that can occur when the {@see ExpectedVersion} is not satisfied in a {@see EventStoreInterface::commit()} call
 * @api
 */
final class ConcurrencyException extends \RuntimeException
{
}
