<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\EventStoreInterface;

/**
 * The two methods of {@see EventStoreInterface} an attempt can be executed through
 *
 * Neither of them supersedes the other: commit() writes to a single stream, commitAll() writes to any
 * number of streams at once, and both are part of the interface. An implementation is free to implement
 * one in terms of the other, but nothing requires it to, so each of them gets its own concurrent coverage.
 */
enum CommitApi: string
{
    /** {@see EventStoreInterface::commit()} */
    case COMMIT = 'commit';

    /** {@see EventStoreInterface::commitAll()} */
    case COMMIT_ALL = 'commitAll';
}
