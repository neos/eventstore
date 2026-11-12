<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\StreamName;

/**
 * A run of consecutive events of one commit that go into the same stream
 *
 * A commit can consist of several segments, and the same stream may appear in more than one of them
 * {@see Segments}.
 */
final readonly class Segment
{
    private function __construct(
        public StreamName $streamName,
        public int $numberOfEvents,
    ) {
    }

    public static function create(StreamName $streamName, int $numberOfEvents): self
    {
        return new self($streamName, $numberOfEvents);
    }

    public static function fromJson(Json $json): self
    {
        return new self(
            StreamName::fromString($json->string('stream')),
            $json->integer('numberOfEvents'),
        );
    }

    /**
     * @return array{stream: string, numberOfEvents: int}
     */
    public function toArray(): array
    {
        return ['stream' => $this->streamName->value, 'numberOfEvents' => $this->numberOfEvents];
    }

    public function toDebugString(): string
    {
        return sprintf('%s×%d', $this->streamName->value, $this->numberOfEvents);
    }
}
