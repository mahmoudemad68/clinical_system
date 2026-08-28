<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Enums\AccountStatus;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Enums\LanguagePreference;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $synthetic = new SyntheticEgyptianData;
        $protector = app(NationalIdProtector::class);
        $phone = $protector->phone($synthetic->mobileNumber());
        $now = now('UTC');

        return [
            'id' => app(IdentityGenerator::class)->next()->value,
            'name' => $synthetic->name()['given'].' '.$synthetic->name()['family'],
            'phone_e164_encrypted' => BinaryColumn::bind($protector->encryptPhone($phone)),
            'phone_lookup_hmac' => BinaryColumn::bind($protector->phoneHmac($phone)),
            'phone_key_version' => 1,
            'password_hash' => Hash::driver('argon2id')->make('password-factory-12'),
            'account_type' => AccountType::Patient->value,
            'status' => AccountStatus::Active->value,
            'language' => LanguagePreference::English->value,
            'credential_version' => 1,
            'phone_verified_at' => $now,
            'last_authenticated_at' => null,
            'bootstrap_exempt' => false,
            'password_must_change' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
