<?php

namespace App\Enums;

enum WorkOrderItemStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Done = 'done';
}
