<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStore;

use Neos\EventStore\ProvidesStatusInterface;

/**
 * The result of a {@see ProvidesStatusInterface::status()} call
 * @api
 */
final class Status
{
    /**
     * @param string[] $errors
     * @param string[] $warnings
     * @param string[] $notices
     */
    private function __construct(
        public readonly array $errors,
        public readonly array $warnings,
        public readonly array $notices,
    ) {
    }

    public static function success(string $notice = null): self
    {
        return new self([], [], $notice !== null ? [$notice] : []);
    }

    public static function error(string $error): self
    {
        return new self([$error], [], []);
    }

    public static function warning(string $warning): self
    {
        return new self([], [$warning], []);
    }
}
