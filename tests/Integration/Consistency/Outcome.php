<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

/**
 * What actually happened when an attempt was executed
 */
enum Outcome: string
{
    /** The store returned a result */
    case SUCCESS = 'SUCCESS';

    /** The store threw a ConcurrencyException – a legitimate outcome unless the verdict was MUST_SUCCEED */
    case REJECTED = 'REJECTED';

    /** The store threw anything else – always a violation */
    case UNEXPECTED_ERROR = 'UNEXPECTED_ERROR';
}
