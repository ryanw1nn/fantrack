<?php

namespace SynergyERP\Shared\Enums;

enum OutboxItemStatus: string
{
    case PENDING = 'pending';
    case PUBLISHING = 'publishing';
    case PUBLISHED = 'published';
    case FAILED = 'failed';
}
