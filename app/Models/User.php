<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'last_lat' => 'float',
        'last_lng' => 'float',
        'last_heading' => 'float',
        'date_of_birth' => 'date',
        'last_seen_at' => 'datetime',
        'location_updated_at' => 'datetime',
        'profile_completed_at' => 'datetime',
    ];

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class, 'user_id');
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class, 'owner_user_id');
    }

    public function places(): HasMany
    {
        return $this->hasMany(UserPlace::class, 'user_id');
    }
}
