<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Identity\Domain\NationalIdProtector;
use App\Modules\Identity\Domain\ValueObjects\AccountStatus;
use App\Modules\Identity\Domain\ValueObjects\AccountType;
use App\Modules\Identity\Domain\ValueObjects\LanguagePreference;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Infrastructure\Persistence\BinaryColumn;
use App\Modules\Platform\Infrastructure\Testing\SyntheticEgyptianData;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

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
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
