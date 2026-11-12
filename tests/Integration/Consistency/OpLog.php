<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

/**
 * Append-only JSONL log of executed attempts
 *
 * Every worker owns its own file, so appending needs no locking and one crashed worker cannot corrupt
 * another's log.
 */
final class OpLog
{
    /**
     * @param resource $handle
     */
    private function __construct(
        private $handle,
    ) {
    }

    public static function open(RunManifest $manifest, string $workerId): self
    {
        $path = $manifest->opLogPath($workerId);
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open op log "%s" for writing', $path), 1781013030);
        }
        return new self($handle);
    }

    public function append(OpLogEntry $entry): void
    {
        fwrite($this->handle, $entry->toJson() . chr(10));
    }

    public function close(): void
    {
        fclose($this->handle);
    }

    /**
     * @return iterable<OpLogEntry>
     */
    public static function readAll(RunManifest $manifest): iterable
    {
        foreach ($manifest->opLogPaths() as $path) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new \RuntimeException(sprintf('Failed to open op log "%s" for reading', $path), 1781013031);
            }
            try {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    yield OpLogEntry::fromJson($line);
                }
            } finally {
                fclose($handle);
            }
        }
    }
}
