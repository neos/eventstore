<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStream;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStream\MaybeVersion;

/**
 * The expected version of a stream when committing new events to it
 * @see EventStoreInterface::commit()
 * @api
 */
final class ExpectedVersion
{
    private const STREAM_EXISTS = -4;
    private const ANY = -2;
    private const NO_STREAM = -1;

    private function __construct(
        public readonly int $value
    ) {
    }

    /**
     * The stream should exist. If it or a metadata stream does not exist treat that as a concurrency problem.
     */
    // phpcs:ignore
    public static function STREAM_EXISTS(): self
    {
        return new self(self::STREAM_EXISTS);
    }

    /**
     * The write operation should not conflict with anything and should always succeed.
     */
    public static function ANY(): self
    {
        return new self(self::ANY);
    }

    /**
     * The stream should not yet exist. If it does exist treat that as a concurrency problem.
     */
    // phpcs:ignore
    public static function NO_STREAM(): self
    {
        return new self(self::NO_STREAM);
    }

    public static function fromVersion(Version $version): self
    {
        return new self($version->value);
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }

    /**
     * @throws ConcurrencyException
     */
    public function verifyVersion(MaybeVersion $version): void
    {
        if (!$this->isSatisfiedBy($version)) {
            throw new ConcurrencyException(sprintf('Expected version: %s, actual version: %s', $this, $version), 1651153651);
        }
    }

    public function isSatisfiedBy(MaybeVersion $version): bool
    {
        if ($version->isNothing()) {
            return in_array($this->value, [self::NO_STREAM, self::ANY], true);
        }
        return match ($this->value) {
            self::STREAM_EXISTS, self::ANY => true,
            self::NO_STREAM => false,
            default => $this->value === $version->unwrap()->value,
        };
    }

    public function __toString(): string
    {
        return match ($this->value) {
            self::STREAM_EXISTS => '-4 [stream exists]',
            self::ANY => '-2 [any]',
            self::NO_STREAM => '-1 [no stream]',
            default => (string)$this->value
        };
    }
}
