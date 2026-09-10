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
