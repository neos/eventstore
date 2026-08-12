<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\StreamName;
use Webmozart\Assert\Assert;

/**
 * The parameters of a single consistency run, written by the prepare step and read by every worker
 * process as well as by the validator
 *
 * Sharing one file rather than re-deriving parameters per process guarantees that all participants of a
 * run agree, gives the run a persisted identity, and makes it possible to re-run the validator against an
 * old run directory.
 */
final readonly class RunManifest
{
    public const NUMBER_OF_DATASETS = 40;

    private function __construct(
        public string $runId,
        public ConsistencyProfile $profile,
        public string $directory,
        public int $numberOfStreams,
        public int $numberOfEventTypes,
        public int $attemptsPerDataset,
        public int $maxEventsPerSegment,
        public int $maxJitterMicroseconds,
        public int $minAttemptsPerShape,
        public float $minSuccessRate,
        public bool $assertMustSucceedIsNotRejected,
    ) {
    }

    public static function forProfile(ConsistencyProfile $profile, string $baseDirectory): self
    {
        $runId = $profile->value . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        return new self(
            runId: $runId,
            profile: $profile,
            directory: $baseDirectory . '/' . $runId,
            numberOfStreams: $profile === ConsistencyProfile::CONTENTION ? 3 : 120,
            numberOfEventTypes: 5,
            attemptsPerDataset: 40,
            maxEventsPerSegment: 3,
            // Race windows in the adapters are microseconds wide. Without deliberately widening the gap
            // between the constraint-building read and the commit, concurrent processes mostly miss each other.
            maxJitterMicroseconds: $profile === ConsistencyProfile::CONTENTION ? 500 : 0,
            minAttemptsPerShape: 20,
            // Measured against the attempts that were not doomed by construction, and only meant to catch a
            // degenerate store that rejects (or swallows) everything – the verdict checks are the precise
            // detectors. Observed on a healthy-ish run: ~46% under contention, ~51% in isolation.
            minSuccessRate: $profile === ConsistencyProfile::CONTENTION ? 0.25 : 0.35,
            // Under heavy contention an adapter may legitimately reject an always-satisfiable commit
            // (deadlock, lock wait timeout, exhausted retries), so this is only conclusive in isolation.
            assertMustSucceedIsNotRejected: $profile === ConsistencyProfile::ISOLATION,
        );
    }

    public static function path(string $baseDirectory): string
    {
        return $baseDirectory . '/manifest.json';
    }

    public function write(string $baseDirectory): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Failed to create consistency run directory "%s"', $this->directory), 1781013001);
        }
        $json = json_encode([
            'runId' => $this->runId,
            'profile' => $this->profile->value,
            'directory' => $this->directory,
            'numberOfStreams' => $this->numberOfStreams,
            'numberOfEventTypes' => $this->numberOfEventTypes,
            'attemptsPerDataset' => $this->attemptsPerDataset,
            'maxEventsPerSegment' => $this->maxEventsPerSegment,
            'maxJitterMicroseconds' => $this->maxJitterMicroseconds,
            'minAttemptsPerShape' => $this->minAttemptsPerShape,
            'minSuccessRate' => $this->minSuccessRate,
            'assertMustSucceedIsNotRejected' => $this->assertMustSucceedIsNotRejected,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        file_put_contents(self::path($baseDirectory), $json);
    }

    public static function tryRead(string $baseDirectory): ?self
    {
        $path = self::path($baseDirectory);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        Assert::isArray($data);
        Assert::keyExists($data, 'profile');
        Assert::string($data['profile']);
        return new self(
            runId: self::readString($data, 'runId'),
            profile: ConsistencyProfile::from($data['profile']),
            directory: self::readString($data, 'directory'),
            numberOfStreams: self::readInt($data, 'numberOfStreams'),
            numberOfEventTypes: self::readInt($data, 'numberOfEventTypes'),
            attemptsPerDataset: self::readInt($data, 'attemptsPerDataset'),
            maxEventsPerSegment: self::readInt($data, 'maxEventsPerSegment'),
            maxJitterMicroseconds: self::readInt($data, 'maxJitterMicroseconds'),
            minAttemptsPerShape: self::readInt($data, 'minAttemptsPerShape'),
            minSuccessRate: self::readFloat($data, 'minSuccessRate'),
            assertMustSucceedIsNotRejected: self::readBool($data, 'assertMustSucceedIsNotRejected'),
        );
    }

    public static function read(string $baseDirectory): self
    {
        $manifest = self::tryRead($baseDirectory);
        if ($manifest === null) {
            throw new \RuntimeException(sprintf('No consistency run manifest found at "%s" – did the prepare step run?', self::path($baseDirectory)), 1781013002);
        }
        return $manifest;
    }

    /**
     * @see AbstractEventStoreConsistencyTestBase::workerId() for why this is not just the pid
     */
    public function opLogPath(string $workerId): string
    {
        return $this->directory . '/ops-' . $workerId . '.jsonl';
    }

    /**
     * @return list<string>
     */
    public function opLogPaths(): array
    {
        $paths = glob($this->directory . '/ops-*.jsonl');
        return $paths === false ? [] : array_values($paths);
    }

    /**
     * @return list<StreamName>
     */
    public function streamNames(): array
    {
        $streamNames = [];
        for ($i = 1; $i <= $this->numberOfStreams; $i++) {
            $streamNames[] = StreamName::fromString('stream-' . $i);
        }
        return $streamNames;
    }

    /**
     * @return list<EventType>
     */
    public function eventTypes(): array
    {
        $eventTypes = [];
        for ($i = 1; $i <= $this->numberOfEventTypes; $i++) {
            $eventTypes[] = EventType::fromString('Events' . $i);
        }
        return $eventTypes;
    }

    public function totalAttempts(): int
    {
        return self::NUMBER_OF_DATASETS * $this->attemptsPerDataset;
    }

    // --- Internal -----

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
    private static function readInt(array $data, string $key): int
    {
        Assert::keyExists($data, $key);
        Assert::integer($data[$key]);
        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function readFloat(array $data, string $key): float
    {
        Assert::keyExists($data, $key);
        Assert::numeric($data[$key]);
        return (float)$data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function readBool(array $data, string $key): bool
    {
        Assert::keyExists($data, $key);
        Assert::boolean($data[$key]);
        return $data[$key];
    }
}
