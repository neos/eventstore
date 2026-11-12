<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

/**
 * Everything a run can accuse an event store of
 *
 * Violations are counted per class and reported in aggregate {@see ValidationReport}, so the class is the
 * unit in which a failing run is read: "every segmented commit is torn" is a different bug report than
 * "one commit out of 1600 lost a constraint".
 */
enum Violation: string
{
    /** Two op log entries share a commit id, which would make the rest of the report ambiguous */
    case DUPLICATE_COMMIT_ID = 'DUPLICATE_COMMIT_ID';

    /** No attempt was logged at all – the run never happened rather than passed */
    case NO_ATTEMPTS = 'NO_ATTEMPTS';

    /** The global order of the store does not strictly ascend */
    case SEQUENCE_NOT_ASCENDING = 'SEQUENCE_NOT_ASCENDING';

    /** An event in the store belongs to no logged commit of this run – the store was not empty when it started */
    case ORPHAN_EVENT = 'ORPHAN_EVENT';

    /** A commit reported success but none of its events are in the store */
    case PHANTOM_SUCCESS = 'PHANTOM_SUCCESS';

    /** A commit reported success but only some of its events are in the store */
    case PARTIAL_WRITE = 'PARTIAL_WRITE';

    /** The events of a commit are in the store, but not the events the commit declared */
    case EVENT_ID_MISMATCH = 'EVENT_ID_MISMATCH';

    /** An event of a commit ended up in a different stream than the layout of that commit declared */
    case SEGMENT_MISMATCH = 'SEGMENT_MISMATCH';

    /** The events of a commit are not in commit order in the global order of the store */
    case COMMIT_ORDER_MISMATCH = 'COMMIT_ORDER_MISMATCH';

    /** The versions a commit wrote to one stream are not consecutive, e.g. because they restart per segment */
    case COMMIT_VERSION_GAP = 'COMMIT_VERSION_GAP';

    /** The store reported a different version for a stream than the one its last event of the commit has */
    case RESULT_VERSION_MISMATCH = 'RESULT_VERSION_MISMATCH';

    /** A commit that did not succeed left events behind – the rollback was not atomic */
    case NON_ATOMIC_ROLLBACK = 'NON_ATOMIC_ROLLBACK';

    /** A commit was accepted although one of its constraints no longer held when it landed */
    case STALE_CONSTRAINT_ACCEPTED = 'STALE_CONSTRAINT_ACCEPTED';

    /** The versions of a stream are not 0..n-1 in the global order of the store */
    case VERSION_GAP = 'VERSION_GAP';

    /** The store threw something other than a ConcurrencyException */
    case UNEXPECTED_ERROR = 'UNEXPECTED_ERROR';

    /** An attempt that could never be satisfied was accepted {@see Verdict::MUST_FAIL} */
    case MUST_FAIL_SUCCEEDED = 'MUST_FAIL_SUCCEEDED';

    /** An attempt that was permanently satisfied was rejected {@see Verdict::MUST_SUCCEED} */
    case MUST_SUCCEED_REJECTED = 'MUST_SUCCEED_REJECTED';

    /** A shape was attempted too rarely for its passing to mean anything */
    case UNDER_COVERED_SHAPE = 'UNDER_COVERED_SHAPE';

    /** Hardly anything succeeded – a store that rejects (or swallows) everything must not pass */
    case LOW_SUCCESS_RATE = 'LOW_SUCCESS_RATE';
}
