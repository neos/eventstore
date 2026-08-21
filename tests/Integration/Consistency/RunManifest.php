<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\StreamName;

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
        $data = Json::decode($contents);
        return new self(
            runId: $data->string('runId'),
            profile: ConsistencyProfile::from($data->string('profile')),
            directory: $data->string('directory'),
            numberOfStreams: $data->integer('numberOfStreams'),
            numberOfEventTypes: $data->integer('numberOfEventTypes'),
            attemptsPerDataset: $data->integer('attemptsPerDataset'),
            maxEventsPerSegment: $data->integer('maxEventsPerSegment'),
            maxJitterMicroseconds: $data->integer('maxJitterMicroseconds'),
            minAttemptsPerShape: $data->integer('minAttemptsPerShape'),
            minSuccessRate: $data->float('minSuccessRate'),
            assertMustSucceedIsNotRejected: $data->boolean('assertMustSucceedIsNotRejected'),
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
        return $paths === false ? [] : $paths;
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
}
