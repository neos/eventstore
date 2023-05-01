<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStream;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\EventTypes;

/**
 * This filter restricts which events are returned by the event store.
 *
 * It is an immutable value object, which is used as filter
 * inside {@see EventStoreInterface::load()}.
 *
 * @api for the public methods; NOT for the inner state.
 */
final class EventStreamFilter
{
    private function __construct(
        public readonly ?EventTypes $eventTypes,
    ) {
    }

    /**
     * Creates an instance with the specified filter options
     *
     * Note: The signature of this method might be extended in the future, so it should always be used with named arguments
     * @see https://www.php.net/manual/en/functions.arguments.php#functions.named-arguments
     *
     * @param EventTypes|null $eventTypes
     */
    public static function create(
        EventTypes $eventTypes = null,
    ): self {
        return new self($eventTypes);
    }

    /**
     * Returns a new instance with the specified additional filter options
     *
     * Note: The signature of this method might be extended in the future, so it should always be used with named arguments
     * @see https://www.php.net/manual/en/functions.arguments.php#functions.named-arguments
     *
     * @param EventTypes|null $eventTypes
     */
    public function with(
        EventTypes $eventTypes = null,
    ): self {
        return self::create(
            $eventTypes ?? $this->eventTypes,
        );
    }
}
