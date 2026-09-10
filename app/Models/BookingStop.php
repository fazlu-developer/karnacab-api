<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStop extends Model
{
    protected $table = 'booking_stops';

    protected $guarded = [];

    public $timestamps = false;
}
