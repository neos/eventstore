<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Webmozart\Assert\Assert;

/**
 * Type safe read access to a decoded JSON object
 *
 * The manifest and the op logs are written by one process and read back by another – potentially even
 * from an earlier run – so nothing about their contents can be assumed. Validating every value in one
 * place keeps that noise out of the classes that describe a run.
 */
final readonly class Json
{
    /**
     * @param array<mixed> $data
     */
    private function __construct(
        private array $data,
    ) {
    }

    public static function decode(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        Assert::isArray($data);
        return new self($data);
    }

    public function string(string $key): string
    {
        $value = $this->value($key);
        Assert::string($value, sprintf('Expected a string for key "%s", got: %%s', $key));
        return $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->value($key);
        Assert::nullOrString($value, sprintf('Expected a string or null for key "%s", got: %%s', $key));
        return $value;
    }

    public function integer(string $key): int
    {
        $value = $this->value($key);
        Assert::integer($value, sprintf('Expected an integer for key "%s", got: %%s', $key));
        return $value;
    }

    public function nullableInteger(string $key): ?int
    {
        $value = $this->value($key);
        Assert::nullOrInteger($value, sprintf('Expected an integer or null for key "%s", got: %%s', $key));
        return $value;
    }

    public function float(string $key): float
    {
        $value = $this->value($key);
        Assert::numeric($value, sprintf('Expected a number for key "%s", got: %%s', $key));
        return (float)$value;
    }

    public function boolean(string $key): bool
    {
        $value = $this->value($key);
        Assert::boolean($value, sprintf('Expected a boolean for key "%s", got: %%s', $key));
        return $value;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->value($key);
        Assert::isArray($value, sprintf('Expected an array for key "%s", got: %%s', $key));
        Assert::allString($value, sprintf('Expected an array of strings for key "%s", got: %%s', $key));
        return array_values($value);
    }

    /**
     * A nested object, wrapped in a reader of its own
     */
    public function object(string $key): self
    {
        $value = $this->value($key);
        Assert::isArray($value, sprintf('Expected an object for key "%s", got: %%s', $key));
        return new self($value);
    }

    /**
     * The nested objects of a list, each wrapped in a reader of its own
     *
     * @return list<self>
     */
    public function objectList(string $key): array
    {
        $value = $this->value($key);
        Assert::isArray($value, sprintf('Expected an array for key "%s", got: %%s', $key));
        $objects = [];
        foreach ($value as $item) {
            Assert::isArray($item, sprintf('Expected an array of objects for key "%s", got: %%s', $key));
            $objects[] = new self($item);
        }
        return $objects;
    }

    private function value(string $key): mixed
    {
        Assert::keyExists($this->data, $key, sprintf('Missing key "%s"', $key));
        return $this->data[$key];
    }
}
