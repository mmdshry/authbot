<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subscriber extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_id',
        'phone',
        'verified',
        'otp',
        'otp_expires_at',
        'last_sent_at',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'otp_expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];
}
