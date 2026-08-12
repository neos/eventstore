<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

/**
 * The catalogue of commit shapes the generator samples from
 *
 * Sampling a named shape (rather than rolling every dimension independently) guarantees that every
 * interesting combination is actually exercised: the validator asserts a minimum number of attempts
 * per shape, so "randomized" can never silently degrade into "never covered".
 */
enum AttemptShape: string
{
    // --- shapes routed through the legacy EventStoreInterface::commit() API -----

    case COMMIT_ANY = 'commit.any';
    case COMMIT_NO_STREAM = 'commit.no_stream';
    case COMMIT_VERSION = 'commit.version';
    case COMMIT_STALE_VERSION = 'commit.stale_version';

    // --- single stream, through commitAll() -----

    case SINGLE_UNCONSTRAINED = 'single.unconstrained';
    case SINGLE_VERSION = 'single.version';
    case SINGLE_STREAM_EXISTS = 'single.stream_exists';
    case SINGLE_STALE_VERSION = 'single.stale_version';
    case SINGLE_NO_STREAM_ON_EXISTING = 'single.no_stream_on_existing';

    // --- multiple streams, through commitAll() -----

    case MULTI_ALL_CONSTRAINED = 'multi.all_constrained';
    case MULTI_PARTIAL = 'multi.partial';
    case SAME_STREAM_SEGMENTED = 'same_stream.segmented';

    // --- constraints on streams the commit does *not* write to, through commitAll() -----

    case FOREIGN_MONOTONE = 'foreign.monotone';
    case FOREIGN_VERSION = 'foreign.version';

    /**
     * Whether this shape is executed via commit() rather than commitAll()
     *
     * Both in-tree adapters implement commit() as a delegation to commitAll(), but the interface does not
     * mandate that, so the legacy API keeps its own concurrent coverage.
     */
    public function usesLegacyCommitApi(): bool
    {
        return match ($this) {
            self::COMMIT_ANY, self::COMMIT_NO_STREAM, self::COMMIT_VERSION, self::COMMIT_STALE_VERSION => true,
            default => false,
        };
    }

    /**
     * The number of *distinct* streams this shape needs in the pool
     */
    public function requiredStreamCount(): int
    {
        return match ($this) {
            self::MULTI_ALL_CONSTRAINED, self::MULTI_PARTIAL, self::FOREIGN_MONOTONE, self::FOREIGN_VERSION => 2,
            default => 1,
        };
    }

    /**
     * Whether this shape can only be built once the process knows a stream to be non-empty
     */
    public function requiresKnownNonEmptyStream(): bool
    {
        return match ($this) {
            self::SINGLE_STREAM_EXISTS, self::SINGLE_NO_STREAM_ON_EXISTING, self::FOREIGN_MONOTONE => true,
            default => false,
        };
    }

    /**
     * Whether this shape needs a stream known to hold at least two events, so that a *stale*
     * (permanently unsatisfiable) version can be picked below the known lower bound
     */
    public function requiresStaleableStream(): bool
    {
        return match ($this) {
            self::COMMIT_STALE_VERSION, self::SINGLE_STALE_VERSION => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
