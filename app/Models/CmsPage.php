<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CmsPage extends Model
{
    protected $table = 'cms_pages';

    protected $guarded = [];

    protected $casts = [
        'body' => 'array',
        'published' => 'boolean',
    ];
}
