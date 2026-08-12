<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

/**
 * The two workload profiles of a consistency run
 *
 * They share generator, op log format and validator – only the knobs and the enabled assertions differ.
 */
enum ConsistencyProfile: string
{
    /**
     * Few streams, jittered attempts: maximizes collisions in order to hunt for corruption.
     * Only the safety assertions and the MUST_FAIL verdict are enforced.
     */
    case CONTENTION = 'contention';

    /**
     * Many streams, no jitter: most attempts are uncontended, so a rejection of an attempt that
     * can only ever be satisfied is a provable liveness bug rather than lock contention.
     */
    case ISOLATION = 'isolation';
}
