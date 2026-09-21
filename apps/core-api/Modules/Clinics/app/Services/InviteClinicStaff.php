<?php

declare(strict_types=1);

namespace Modules\Clinics\Services;

use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Clinics\Enums\ClinicInvitationStatus;
use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Support\ClinicInvitationOutcome;
use Modules\Clinics\Support\ClinicLocationRowFactory;
use Modules\Clinics\Support\ClinicOwnerGuard;
use Modules\Clinics\Support\ClinicStaffInvitationRecord;
use Modules\Identity\Services\InvitationRecipientService;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Support\Identifier;

/**
 * Invite a secretary to one clinic location. Phone is bound via Identity HMAC
 * only. Responses never disclose whether the phone belongs to an account.
 *
 * Listed in ApprovedCoordinators.
 */
final class InviteClinicStaff
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresClinicStore $store,
        private readonly ClinicLocationRowFactory $rows,
        private readonly ClinicOwnerGuard $guard,
        private readonly InvitationRecipientService $recipients,
        private readonly AppendAuditEvent $audit,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(ActorContext $actor, Identifier $locationId, array $input): ClinicInvitationOutcome
    {
        $this->guard->requireOwnedLocation($actor, $locationId, Capabilities::CLINICS_STAFF_INVITE);

        $binding = $this->recipients->bindPhone((string) $input['phone']);

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $locationId, $binding): ClinicInvitationOutcome {
            $this->guard->requireOwnedLocation(
                $actor,
                $locationId,
                Capabilities::CLINICS_STAFF_INVITE,
                true,
            );
            $this->store->lockLookupIndex('invite:'.$locationId->value.':'.bin2hex($binding->phoneLookupHmac));

            $existing = $this->store->findPendingInvitation($locationId, $binding->phoneLookupHmac, true);
            if ($existing instanceof ClinicStaffInvitationRecord) {
                return new ClinicInvitationOutcome(
                    $existing->id->value,
                    $existing->locationId->value,
                    $existing->status,
                    $existing->expiresAt,
                    false,
                );
            }

            $now = $this->clock->now();
            $hours = (int) config('clinics_module.invitation_ttl_hours', 72);
            $expiresAt = $now->modify(sprintf('+%d hours', $hours));
            $invitationId = $this->ids->next();

            try {
                $this->store->insertInvitation($this->rows->invitationAttributes(
                    $invitationId,
                    $locationId,
                    $binding->phoneLookupHmac,
                    $binding->hmacVersion,
                    $actor->userId,
                    $now,
                    $expiresAt,
                ));
            } catch (DuplicateIdentity) {
                $retry = $this->store->findPendingInvitation($locationId, $binding->phoneLookupHmac, true);
                if ($retry instanceof ClinicStaffInvitationRecord) {
                    return new ClinicInvitationOutcome(
                        $retry->id->value,
                        $retry->locationId->value,
                        $retry->status,
                        $retry->expiresAt,
                        false,
                    );
                }

                throw new DuplicateIdentity;
            }

            $this->audit->append(
                $tx,
                'clinic.staff_invitation_created',
                'clinic_staff_invitation',
                $invitationId,
                ['reason_code' => 'owner_invite', 'location_id' => $locationId->value],
                $actor->userId,
                'user',
            );

            return new ClinicInvitationOutcome(
                $invitationId->value,
                $locationId->value,
                ClinicInvitationStatus::Pending,
                $expiresAt,
                true,
            );
        });
    }
}
