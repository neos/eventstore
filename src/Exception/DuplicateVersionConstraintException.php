<?php

declare(strict_types=1);

namespace Neos\EventStore\Exception;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamExists;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStream;

/**
 * Exception that can occur when there are multiple expected versions for the same content stream for {@see EventStoreInterface::commitAll()}
 * @api
 */
final class DuplicateVersionConstraintException extends \InvalidArgumentException
{
    public static function becauseExpectedStreamVersionIsDuplicate(ExpectedVersionForStream|ExpectedNoStream|ExpectedStreamExists $first, ExpectedVersionForStream|ExpectedNoStream|ExpectedStreamExists $other): self
    {
        return new self(sprintf('Duplicate constraint %s and %s', $first->toDebugString(), $other->toDebugString()), 1780752238);
    }
}
