<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected $table = 'bookings';

    protected $guarded = [];

    protected $casts = [
        'quote_snapshot' => 'array',
        'scheduled_at' => 'datetime',
        'return_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(BookingStop::class, 'booking_id');
    }
}
