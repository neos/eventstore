<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStore;

/**
 * @see Status
 * @api
 */
enum StatusType
{
    case OK;
    case ERROR;
    case SETUP_REQUIRED;
}
