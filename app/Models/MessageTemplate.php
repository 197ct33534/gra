<?php

namespace App\Models;

use App\Enums\CustomerMessageType;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'channel',
        'template_code',
        'body_fallback',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerMessageType::class,
            'is_active' => 'boolean',
        ];
    }
}
