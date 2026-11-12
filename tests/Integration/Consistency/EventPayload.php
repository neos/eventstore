<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\EventData;

/**
 * The payload every generated event carries
 *
 * It is what makes an event found in the store traceable back to the attempt that wrote it, and it is
 * self-describing on purpose: an event that knows its own position within its commit, and how many events
 * that commit had in total, is enough to spot a torn or reordered commit without consulting anything else.
 */
final readonly class EventPayload
{
    private function __construct(
        public string $runId,
        public string $commitId,
        public int $positionInCommit,
        public int $numberOfEventsInCommit,
    ) {
    }

    public static function create(string $runId, string $commitId, int $positionInCommit, int $numberOfEventsInCommit): self
    {
        return new self($runId, $commitId, $positionInCommit, $numberOfEventsInCommit);
    }

    public function toEventData(): EventData
    {
        return EventData::fromString(json_encode([
            'runId' => $this->runId,
            'commitId' => $this->commitId,
            'positionInCommit' => $this->positionInCommit,
            'numberOfEventsInCommit' => $this->numberOfEventsInCommit,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Null for an event that was not written by this harness at all – the store was not empty when the run
     * started, which the validator reports rather than trips over
     */
    public static function tryFromEventData(EventData $data): ?self
    {
        try {
            $json = Json::decode($data->value);
            return new self(
                $json->string('runId'),
                $json->string('commitId'),
                $json->integer('positionInCommit'),
                $json->integer('numberOfEventsInCommit'),
            );
        } catch (\JsonException | \InvalidArgumentException) {
            return null;
        }
    }
}
