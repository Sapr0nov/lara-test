<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    protected $fillable = [
        'wallet_id',
        'coin',
        'operation',
        'tx_hash',
        'status',
        'amount',
        'confirmations',
        'required_confirmations',
        'reference_type',
        'reference_id',
    ];
}
