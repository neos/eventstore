<?php
declare(strict_types=1);
namespace Neos\EventStore\Exception;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStreams;
use Neos\EventStore\Model\EventStream\MaybeVersion;

/**
 * Exception that can occur when the {@see ExpectedVersion} is not satisfied in a {@see EventStoreInterface::commit()} call
 * @api
 */
final class ConcurrencyException extends \RuntimeException
{
    public static function becauseVersionOfStreamDoesNotMatchExpected(ExpectedVersion $expectedVersion, MaybeVersion $actualVersion, StreamName $streamName, ExpectedVersionForStreams $expectedVersionForStreams): self
    {
        return new self(sprintf(
            'Expected version: %s for stream "%s", actual version: %s.%s',
            $expectedVersion->__toString(),
            $streamName->value,
            $actualVersion->__toString(),
            $expectedVersionForStreams->count() > 1 ? ' All: ' . $expectedVersionForStreams->toDebugString() : ''
        ), 1779022349);
    }
}
