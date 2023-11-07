<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStream;

use Neos\EventStore\Model\Event\Version;

/**
 * An [option type](https://en.wikipedia.org/wiki/Option_type) that represents a {@see Version} or nothing
 * @api
 */
final class MaybeVersion
{
    private function __construct(
        private readonly ?Version $version
    ) {
    }

    public static function fromVersionOrNull(?Version $version): self
    {
        return new self($version);
    }

    public function versionOr(mixed $fallback): mixed
    {
        return $this->version ?? $fallback;
    }

    public function isNothing(): bool
    {
        return $this->version === null;
    }

    public function unwrap(): Version
    {
        if ($this->version === null) {
            throw new \RuntimeException('Failed to unwrap version from nothing');
        }
        return $this->version;
    }

    public function __toString(): string
    {
        return $this->version === null ? '[none]' : (string)$this->version->value;
    }
}
