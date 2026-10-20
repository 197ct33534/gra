<?php

namespace App\Enums;

enum WorkOrderItemType: string
{
    case Service = 'service';
    case Part = 'part';
}
