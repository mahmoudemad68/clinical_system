<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Authenticatable adapter over Identity `users`.
 *
 * Other modules must not import this model to read identity tables; they call
 * Identity ports. Laravel's session guard is the exception.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'users';

    protected $guarded = ['*'];

    protected $hidden = ['password_hash', 'phone_e164_encrypted', 'phone_lookup_hmac'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'password_must_change' => false,
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'last_authenticated_at' => 'datetime',
            'bootstrap_exempt' => 'boolean',
            'password_must_change' => 'boolean',
            'credential_version' => 'integer',
        ];
    }
}
