<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\MaybeVersion;
use Neos\EventStore\Tests\Integration\Consistency\Attempt;
use Neos\EventStore\Tests\Integration\Consistency\AttemptGenerator;
use Neos\EventStore\Tests\Integration\Consistency\ConsistencyProfile;
use Neos\EventStore\Tests\Integration\Consistency\ConsistencyValidator;
use Neos\EventStore\Tests\Integration\Consistency\OpLog;
use Neos\EventStore\Tests\Integration\Consistency\OpLogEntry;
use Neos\EventStore\Tests\Integration\Consistency\Outcome;
use Neos\EventStore\Tests\Integration\Consistency\RunManifest;
use Neos\EventStore\Tests\Integration\Consistency\StreamKnowledge;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Concurrency test base for event store adapters
 *
 * A run has three steps, which have to be invoked separately because the middle one is executed by many
 * processes at once (see the `test:consistency` composer scripts of an adapter):
 *
 *   1. `prepareContentionProfile()` / `prepareIsolationProfile()` – reset the store, mint a run manifest
 *   2. `test_consistency()` – run under paratest, many processes hammering the store concurrently
 *   3. `validate()` – cross-check the store against the op logs of all processes
 *
 * Each attempt is generated from a named shape and tagged with what can be *proven* about its outcome
 * beforehand, so the oracle does not depend on how the processes happened to interleave. See
 * {@see AttemptGenerator} and {@see ConsistencyValidator}.
 */
#[CoversNothing]
abstract class AbstractEventStoreConsistencyTestBase extends TestCase
{
    abstract protected static function createEventStore(): EventStoreInterface;

    abstract protected static function resetEventStore(): void;

    /**
     * Human readable description of the store under test, rendered by the prepare step
     *
     * A run is only meaningful together with what it ran against, and that is not visible from the manifest:
     * adapters are configured through the environment (DSN, table name, …), so an adapter should mention
     * whatever distinguishes this execution – e.g. the platform of the current Doctrine connection.
     */
    protected static function eventStoreDescription(): ?string
    {
        return null;
    }

    /**
     * Where manifest and op logs of a run are kept, relative to the working directory the test is invoked from
     */
    protected static function consistencyRunDirectory(): string
    {
        return getcwd() . '/.consistency-run';
    }

    // --- Composer script entry points -----

    /**
     * Few streams and jittered attempts: hunts for corruption under maximum collision pressure
     */
    public static function prepareContentionProfile(): void
    {
        static::prepareProfile(ConsistencyProfile::CONTENTION);
    }

    /**
     * Many streams and no jitter: most attempts are uncontended, so rejecting an always-satisfiable
     * commit becomes a provable liveness bug rather than lock contention
     */
    public static function prepareIsolationProfile(): void
    {
        static::prepareProfile(ConsistencyProfile::ISOLATION);
    }

    public static function validate(): void
    {
        $baseDirectory = static::consistencyRunDirectory();
        $manifest = RunManifest::read($baseDirectory);
        $report = ConsistencyValidator::validate(static::createEventStore(), $manifest);
        echo $report->digest();
        if ($report->hasViolations()) {
            throw new \RuntimeException($report->summary(), 1781013040);
        }
        self::removeDirectory($manifest->directory);
        @unlink(RunManifest::path($baseDirectory));
    }

    // --- Write phase -----

    /**
     * @return iterable<array{int}>
     */
    public static function consistency_dataProvider(): iterable
    {
        for ($dataset = 0; $dataset < RunManifest::NUMBER_OF_DATASETS; $dataset++) {
            yield [$dataset];
        }
    }

    #[DataProvider('consistency_dataProvider')]
    public function test_consistency(int $dataset): void
    {
        $manifest = RunManifest::tryRead(static::consistencyRunDirectory());
        if ($manifest === null) {
            self::markTestSkipped(sprintf('No consistency run prepared – invoke %s::prepareContentionProfile() first (see the test:consistency composer script)', static::class));
        }
        $pid = getmypid();
        if ($pid === false) {
            $pid = 0;
        }
        $workerId = self::workerId($dataset, $pid);
        $eventStore = static::createEventStore();
        $knowledge = new StreamKnowledge();
        $generator = new AttemptGenerator(
            $manifest,
            $knowledge,
            static fn (StreamName $streamName) => self::readStreamVersion($eventStore, $streamName),
            $workerId,
        );
        $opLog = OpLog::open($manifest, $workerId);
        $numberOfAttempts = 0;
        try {
            for ($i = 0; $i < $manifest->attemptsPerDataset; $i++) {
                $attempt = $generator->generate();
                // Widening the gap between the constraint-building read and the commit is what makes the
                // microsecond-wide race windows in the adapters actually reachable
                $jitter = $manifest->maxJitterMicroseconds > 0 ? random_int(0, $manifest->maxJitterMicroseconds) : 0;
                if ($jitter > 0) {
                    usleep($jitter);
                }
                $opLog->append(self::execute($eventStore, $manifest, $attempt, $knowledge, $dataset, $pid, $jitter));
                $numberOfAttempts++;
            }
        } finally {
            $opLog->close();
        }
        self::assertSame($manifest->attemptsPerDataset, $numberOfAttempts);
    }

    // --- Internal -----

    /**
     * Identifies one execution of the write phase
     *
     * Neither the pid nor the dataset index identifies it on their own: pids get recycled once a worker
     * exits, and paratest re-executes data provider rows (a run of 40 rows reliably produces noticeably
     * more than 40 executions), so the same (dataset, pid) pair can legitimately occur twice within one
     * run. A random token per execution keeps commit ids and op log file names collision free.
     */
    private static function workerId(int $dataset, int $pid): string
    {
        return $dataset . '-' . $pid . '-' . bin2hex(random_bytes(3));
    }

    protected static function prepareProfile(ConsistencyProfile $profile): void
    {
        $baseDirectory = static::consistencyRunDirectory();
        if (!is_dir($baseDirectory) && !mkdir($baseDirectory, 0777, true) && !is_dir($baseDirectory)) {
            throw new \RuntimeException(sprintf('Failed to create consistency run directory "%s"', $baseDirectory), 1781013041);
        }
        // A leftover manifest from a failed run would make the workers write into the wrong run directory
        @unlink(RunManifest::path($baseDirectory));
        static::createEventStore()->setup();
        static::resetEventStore();
        $manifest = RunManifest::forProfile($profile, $baseDirectory);
        $manifest->write($baseDirectory);
        $eventStoreDescription = static::eventStoreDescription();
        echo sprintf(
            'Prepared consistency run "%s": %d streams, %d datasets × %d attempts, jitter up to %dµs%s',
            $manifest->runId,
            $manifest->numberOfStreams,
            RunManifest::NUMBER_OF_DATASETS,
            $manifest->attemptsPerDataset,
            $manifest->maxJitterMicroseconds,
            chr(10),
        );
        if ($eventStoreDescription !== null) {
            echo sprintf('Event store: %s%s', $eventStoreDescription, chr(10));
        }
    }

    /**
     * Executes a single attempt and records everything the validator needs about it
     *
     * Every throwable is caught – including the unexpected ones – so that one adapter limitation cannot
     * kill a worker and take the rest of its run's data with it. Unexpected errors are a violation, but
     * they are reported by the validator at the end, in aggregate.
     */
    private static function execute(
        EventStoreInterface $eventStore,
        RunManifest $manifest,
        Attempt $attempt,
        StreamKnowledge $knowledge,
        int $dataset,
        int $pid,
        int $jitterMicroseconds,
    ): OpLogEntry {
        $outcome = Outcome::SUCCESS;
        $highestSequenceNumber = null;
        $resultVersions = [];
        $errorClass = null;
        $errorMessage = null;
        try {
            if ($attempt->usesLegacyCommitApi()) {
                $streamName = $attempt->legacyStreamName();
                $commitResult = $eventStore->commit($streamName, $attempt->legacyEvents(), $attempt->legacyExpectedVersion());
                $highestSequenceNumber = $commitResult->highestCommittedSequenceNumber->value;
                $resultVersions[$streamName->value] = $commitResult->highestCommittedVersion->value;
                $knowledge->recordVersion($streamName, $commitResult->highestCommittedVersion);
            } else {
                $commitAllResult = $eventStore->commitAll($attempt->commit);
                $highestSequenceNumber = $commitAllResult->highestCommittedSequenceNumber->value;
                foreach ($commitAllResult->versionForStreams as $versionForStream) {
                    $resultVersions[$versionForStream->streamName->value] = $versionForStream->version->value;
                }
                $knowledge->recordCommitResult($commitAllResult);
            }
        } catch (ConcurrencyException $exception) {
            $outcome = Outcome::REJECTED;
            $errorClass = $exception::class;
            $errorMessage = $exception->getMessage();
        } catch (\Throwable $exception) {
            $outcome = Outcome::UNEXPECTED_ERROR;
            $errorClass = $exception::class;
            $errorMessage = $exception->getMessage();
        }
        return new OpLogEntry(
            runId: $manifest->runId,
            commitId: $attempt->commitId,
            pid: $pid,
            dataset: $dataset,
            shape: $attempt->shape,
            verdict: $attempt->verdict,
            verdictReason: $attempt->verdictReason,
            api: $attempt->usesLegacyCommitApi() ? 'commit' : 'commitAll',
            segments: $attempt->segments,
            constraints: $attempt->constraints,
            eventIds: $attempt->eventIds,
            jitterMicroseconds: $jitterMicroseconds,
            outcome: $outcome,
            highestSequenceNumber: $highestSequenceNumber,
            resultVersions: $resultVersions,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
        );
    }

    private static function readStreamVersion(EventStoreInterface $eventStore, StreamName $streamName): MaybeVersion
    {
        foreach ($eventStore->load($streamName)->backwards() as $eventEnvelope) {
            return MaybeVersion::fromVersionOrNull($eventEnvelope->version);
        }
        return MaybeVersion::fromVersionOrNull(null);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $files = glob($directory . '/*');
        foreach ($files === false ? [] : $files as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}
