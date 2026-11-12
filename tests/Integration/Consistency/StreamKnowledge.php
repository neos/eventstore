<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStream\MaybeVersion;

/**
 * A process' monotonically growing knowledge about stream versions
 *
 * Only *lower bounds* are recorded and they are never decremented, which is what makes them usable
 * without re-reading: because stream versions only ever grow and events are never deleted during a run,
 * a stale lower bound stays a valid lower bound forever.
 *
 * That is enough to construct constraints whose outcome is provable without any timing reasoning:
 * a version *below* a known lower bound can never be satisfied again, and a stream known to be
 * non-empty can never become empty again.
 *
 * Note: this rests on nothing deleting events mid-run. deleteStream() is adapter-specific and not part
 * of EventStoreInterface, and resetEventStore() only runs in the prepare step.
 */
final class StreamKnowledge
{
    /**
     * Highest version known to exist per stream. Absence means "nothing known", NOT "empty" –
     * version 0 is the first event of a stream, so 0 already implies a non-empty stream.
     *
     * @var array<string, Version>
     */
    private array $lowerBounds = [];

    public function recordVersion(StreamName $streamName, Version $version): void
    {
        $current = $this->lowerBounds[$streamName->value] ?? null;
        if ($current === null || $version->value > $current->value) {
            $this->lowerBounds[$streamName->value] = $version;
        }
    }

    /**
     * Records what a read returned. An *empty* stream teaches us nothing monotone (it may become
     * non-empty at any moment), so it is deliberately not recorded.
     */
    public function recordObservation(StreamName $streamName, MaybeVersion $maybeVersion): void
    {
        if ($maybeVersion->isNothing()) {
            return;
        }
        $this->recordVersion($streamName, $maybeVersion->unwrap());
    }

    public function recordCommittedVersions(CommittedVersions $committedVersions): void
    {
        foreach ($committedVersions as $versionForStream) {
            $this->recordVersion($versionForStream->streamName, $versionForStream->version);
        }
    }

    public function lowerBound(StreamName $streamName): ?Version
    {
        return $this->lowerBounds[$streamName->value] ?? null;
    }

    public function isKnownNonEmpty(StreamName $streamName): bool
    {
        return isset($this->lowerBounds[$streamName->value]);
    }

    /**
     * Streams known to hold at least two events, so a permanently unsatisfiable version can be picked below the bound
     */
    public function isStaleable(StreamName $streamName): bool
    {
        $lowerBound = $this->lowerBound($streamName);
        return $lowerBound !== null && $lowerBound->value >= 1;
    }
}
