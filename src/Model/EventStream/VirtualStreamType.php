<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStream;

/**
 * The type of {@see VirtualStreamName}
 * @api
 */
enum VirtualStreamType: string
{
    case ALL = 'all';
    case CATEGORY = 'category';
    case CORRELATION_ID = 'correlation';
}
