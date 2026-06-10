<?php
declare(strict_types=1);
namespace Neos\EventStore\Exception;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamExists;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\ExpectedStreamVersion;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStreams;
use Neos\EventStore\Model\EventStream\MaybeVersion;

/**
 * Exception that can occur when the {@see ExpectedVersion} is not satisfied in a {@see EventStoreInterface::commit()} call
 * @api
 */
final class ConcurrencyException extends \RuntimeException
{
    public static function becauseVersionOfStreamDoesNotMatchExpected(ExpectedStreamVersion|ExpectedNoStream|ExpectedStreamExists $expectedVersionForStream, MaybeVersion $actualVersion, ExpectedVersionForStreams $expectedVersionForStreams): self
    {
        return new self(sprintf(
            'Expected version: %s, actual version: %s.%s',
            $expectedVersionForStream->toDebugString(),
            $actualVersion->toDebugString(),
            $expectedVersionForStreams->count() > 1 ? ' All: ' . $expectedVersionForStreams->toDebugString() : ''
        ), 1779022349);
    }
}
