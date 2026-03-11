<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case New = 'new';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Failed = 'failed';
    case Reversed = 'reversed';
}
