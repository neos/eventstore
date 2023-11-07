<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit\Helper;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Helper\InMemoryEventStore;
use Neos\EventStore\Tests\Integration\AbstractEventStoreTestBase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryEventStore::class)]
final class InMemoryEventStoreTest extends AbstractEventStoreTestBase
{

    protected function createEventStore(): EventStoreInterface
    {
        return new InMemoryEventStore();
    }
}
