<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamExists;
use Neos\EventStore\Model\EventStream\ExpectedStreamVersion;
use Webmozart\Assert\Assert;

/**
 * One executed attempt: what the process intended, and what the store did about it
 *
 * The op log is what makes the oracle two-directional. Validating from the event payloads alone can only
 * ever inspect events that *were* written, which is structurally blind to a commit that reported success
 * but wrote nothing, and to a commit that threw but wrote anyway.
 */
final readonly class OpLogEntry
{
    /**
     * @param list<array{stream: string, count: int}> $segments
     * @param list<ExpectedStreamVersion|ExpectedNoStream|ExpectedStreamExists> $constraints
     * @param list<string> $eventIds
     * @param array<string, int> $resultVersions the per-stream versions the store reported, keyed by stream name
     */
    public function __construct(
        public string $runId,
        public string $commitId,
        public int $pid,
        public int $dataset,
        public AttemptShape $shape,
        public Verdict $verdict,
        public string $verdictReason,
        public CommitApi $commitApi,
        public array $segments,
        public array $constraints,
        public array $eventIds,
        public int $jitterMicroseconds,
        public Outcome $outcome,
        public ?int $highestSequenceNumber,
        public array $resultVersions,
        public ?string $errorClass,
        public ?string $errorMessage,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'runId' => $this->runId,
            'commitId' => $this->commitId,
            'pid' => $this->pid,
            'dataset' => $this->dataset,
            'shape' => $this->shape->value,
            'verdict' => $this->verdict->value,
            'verdictReason' => $this->verdictReason,
            'commitApi' => $this->commitApi->value,
            'segments' => $this->segments,
            'constraints' => array_map(self::constraintToArray(...), $this->constraints),
            'eventIds' => $this->eventIds,
            'jitterMicroseconds' => $this->jitterMicroseconds,
            'outcome' => $this->outcome->value,
            'highestSequenceNumber' => $this->highestSequenceNumber,
            'resultVersions' => (object)$this->resultVersions,
            'errorClass' => $this->errorClass,
            'errorMessage' => $this->errorMessage,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        Assert::isArray($data);

        $segments = [];
        Assert::keyExists($data, 'segments');
        Assert::isArray($data['segments']);
        foreach ($data['segments'] as $segment) {
            Assert::isArray($segment);
            Assert::keyExists($segment, 'stream');
            Assert::string($segment['stream']);
            Assert::keyExists($segment, 'count');
            Assert::integer($segment['count']);
            $segments[] = ['stream' => $segment['stream'], 'count' => $segment['count']];
        }

        $resultVersions = [];
        Assert::keyExists($data, 'resultVersions');
        Assert::isArray($data['resultVersions']);
        foreach ($data['resultVersions'] as $streamName => $version) {
            Assert::string($streamName);
            Assert::integer($version);
            $resultVersions[$streamName] = $version;
        }

        return new self(
            runId: self::readString($data, 'runId'),
            commitId: self::readString($data, 'commitId'),
            pid: self::readInt($data, 'pid'),
            dataset: self::readInt($data, 'dataset'),
            shape: AttemptShape::from(self::readString($data, 'shape')),
            verdict: Verdict::from(self::readString($data, 'verdict')),
            verdictReason: self::readString($data, 'verdictReason'),
            commitApi: CommitApi::from(self::readString($data, 'commitApi')),
            segments: $segments,
            constraints: self::readConstraints($data),
            eventIds: self::readStringList($data, 'eventIds'),
            jitterMicroseconds: self::readInt($data, 'jitterMicroseconds'),
            outcome: Outcome::from(self::readString($data, 'outcome')),
            highestSequenceNumber: self::readNullableInt($data, 'highestSequenceNumber'),
            resultVersions: $resultVersions,
            errorClass: self::readNullableString($data, 'errorClass'),
            errorMessage: self::readNullableString($data, 'errorMessage'),
        );
    }

    /**
     * A compact one-line rendering used in the violation digest
     */
    public function toDebugString(): string
    {
        $segments = implode(' + ', array_map(static fn (array $segment) => $segment['stream'] . '×' . $segment['count'], $this->segments));
        return sprintf(
            '%s [%s via %s] writes %s, expects [%s], %s: %s%s',
            $this->commitId,
            $this->shape->value,
            $this->commitApi->value,
            $segments === '' ? '(nothing)' : $segments,
            implode(', ', array_map(static fn ($constraint) => $constraint->toDebugString(), $this->constraints)),
            $this->verdict->value,
            $this->outcome->value,
            $this->errorClass === null ? '' : sprintf(' (%s: %s)', $this->errorClass, $this->errorMessage ?? ''),
        );
    }

    // --- Internal -----

    /**
     * Constraints are logged structurally rather than as debug strings, because the validator has to
     * re-evaluate them against the state the store was in when the commit landed
     *
     * @return array{stream: string, expectation: string, version: int|null}
     */
    private static function constraintToArray(ExpectedStreamVersion|ExpectedNoStream|ExpectedStreamExists $constraint): array
    {
        return [
            'stream' => $constraint->streamName->value,
            'expectation' => match (true) {
                $constraint instanceof ExpectedStreamVersion => 'version',
                $constraint instanceof ExpectedNoStream => 'no_stream',
                $constraint instanceof ExpectedStreamExists => 'exists',
            },
            'version' => $constraint instanceof ExpectedStreamVersion ? $constraint->expectedVersion->value : null,
        ];
    }

    /**
     * @param array<mixed> $data
     * @return list<ExpectedStreamVersion|ExpectedNoStream|ExpectedStreamExists>
     */
    private static function readConstraints(array $data): array
    {
        Assert::keyExists($data, 'constraints');
        Assert::isArray($data['constraints']);
        $constraints = [];
        foreach ($data['constraints'] as $constraint) {
            Assert::isArray($constraint);
            $streamName = StreamName::fromString(self::readString($constraint, 'stream'));
            $expectation = self::readString($constraint, 'expectation');
            $constraints[] = match ($expectation) {
                'version' => ExpectedStreamVersion::create($streamName, Version::fromInteger(self::readInt($constraint, 'version'))),
                'no_stream' => ExpectedNoStream::create($streamName),
                'exists' => ExpectedStreamExists::create($streamName),
                default => throw new \RuntimeException(sprintf('Unsupported constraint expectation "%s"', $expectation), 1781013030),
            };
        }
        return $constraints;
    }

    /**
     * @param array<mixed> $data
     */
    private static function readString(array $data, string $key): string
    {
        Assert::keyExists($data, $key);
        Assert::string($data[$key]);
        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function readNullableString(array $data, string $key): ?string
    {
        Assert::keyExists($data, $key);
        Assert::nullOrString($data[$key]);
        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function readInt(array $data, string $key): int
    {
        Assert::keyExists($data, $key);
        Assert::integer($data[$key]);
        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function readNullableInt(array $data, string $key): ?int
    {
        Assert::keyExists($data, $key);
        Assert::nullOrInteger($data[$key]);
        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function readStringList(array $data, string $key): array
    {
        Assert::keyExists($data, $key);
        Assert::isArray($data[$key]);
        Assert::allString($data[$key]);
        return array_values($data[$key]);
    }
}
