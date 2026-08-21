<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

/**
 * What the generator can prove about the outcome of an attempt *before* it is executed
 *
 * The proofs rest on two monotonicity properties that hold for the duration of a run:
 * stream versions only ever grow, and events are never deleted.
 */
enum Verdict: string
{
    /** At least one constraint can never be satisfied again – the store must reject this attempt */
    case MUST_FAIL = 'MUST_FAIL';

    /** Every constraint is permanently satisfied (or there are none) – the store must accept this attempt */
    case MUST_SUCCEED = 'MUST_SUCCEED';

    /** The outcome depends on the interleaving with other processes – both outcomes are correct */
    case UNDECIDABLE = 'UNDECIDABLE';
}
