<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use Modules\Auth\Services\RegisterAccountService;
use Modules\Doctors\Services\RegisterDoctor;
use Modules\Identity\Services\DisableIdentityService;
use Modules\Identity\Services\EraseSubjectService;
use Modules\Identity\Services\RotateIdentityKeysService;
use Modules\Patients\Services\CreatePatientProfile;
use Modules\Patients\Services\CreateUnlinkedPatientProfile;
use Modules\Patients\Services\ResolvePatientHandle;
use Modules\Patients\Services\UpdateOwnDemographics;
use Modules\Platform\Services\Coordinators\ApprovedCoordinators;
use Modules\Platform\Services\Outbox\OutboxConsumer;
use Modules\Verification\Services\VerificationDocumentService;
use Modules\Verification\Services\VerificationService;
use Modules\Verification\Services\VerificationUploadProcessor;
use Modules\Verification\Services\VerificationUploadService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Architecture tests that deptrac does not cover: coordinating-service
 * allow-list, rejection of DDD directories, consumers staying inside Platform.
 */
final class ArchitectureBoundaryTest extends TestCase
{
    #[Test]
    public function phase_01_lists_the_registration_disable_and_erase_coordinating_services(): void
    {
        $this->assertContains(
            RegisterAccountService::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            DisableIdentityService::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            EraseSubjectService::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            RotateIdentityKeysService::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            CreatePatientProfile::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            UpdateOwnDemographics::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            CreateUnlinkedPatientProfile::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            ResolvePatientHandle::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            RegisterDoctor::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            VerificationService::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            VerificationDocumentService::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            VerificationUploadProcessor::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            VerificationUploadService::class,
            ApprovedCoordinators::classes(),
        );
    }

    #[Test]
    public function coordinator_suffix_classes_must_not_return(): void
    {
        $found = [];

        foreach ($this->phpFiles(basePath: $this->modulesRoot()) as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/^final class (\w+Coordinator)\b/m', $contents, $match) !== 1) {
                continue;
            }

            if ($match[1] === 'ApprovedCoordinators') {
                continue;
            }

            $found[] = $match[1];
        }

        $this->assertSame(
            [],
            $found,
            'Cross-module writers are *Service classes listed in ApprovedCoordinators, not *Coordinator types.',
        );
    }

    #[Test]
    public function ddd_directory_trees_must_not_return(): void
    {
        $this->assertDirectoryDoesNotExist(dirname(__DIR__, 3).'/app/Modules');

        $forbidden = [];

        foreach ($this->phpFiles($this->modulesRoot()) as $file) {
            if (preg_match('#/app/(Domain|Application|Infrastructure)(/|$)#', $file) === 1) {
                $forbidden[] = $file;
            }
        }

        $this->assertSame(
            [],
            $forbidden,
            'Modules must not reintroduce Domain, Application, or Infrastructure directories.',
        );
    }

    #[Test]
    public function platform_kernel_does_not_import_business_modules(): void
    {
        $platform = $this->modulesRoot().DIRECTORY_SEPARATOR.'Platform';

        foreach ($this->phpFiles($platform) as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/^use Modules\\\\(Auth|Identity|Access|Audit|Patients|Doctors|Verification)\\\\/m',
                $contents,
                $file.' Platform must not import a business module. List coordinating services as class-string names.',
            );
        }
    }

    #[Test]
    public function outbox_consumers_do_not_import_another_modules_persistence(): void
    {
        foreach ($this->phpFiles($this->modulesRoot()) as $file) {
            $contents = (string) file_get_contents($file);

            if (! str_contains($contents, 'implements OutboxConsumer')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/use Modules\\\\(?!Platform\\\\).*\\\\(Models|Services\\\\Persistence)\\\\/',
                $contents,
                $file.' consumer must not import another module\'s persistence types.',
            );
        }

        $this->assertTrue(interface_exists(OutboxConsumer::class));
    }

    #[Test]
    public function platform_idempotency_does_not_encode_patient_profiles(): void
    {
        $file = $this->modulesRoot().DIRECTORY_SEPARATOR.'Platform/app/Http/Middleware/EnforceIdempotency.php';
        $contents = (string) file_get_contents($file);

        $this->assertStringNotContainsString('patient_profile', $contents);
        $this->assertStringNotContainsString('patient_id', $contents);
        $this->assertStringNotContainsString('doctor_profile', $contents);
        $this->assertStringNotContainsString('doctor_id', $contents);
        $this->assertStringNotContainsString('specialty', $contents);
    }

    #[Test]
    public function identity_does_not_query_patients_or_doctors_tables(): void
    {
        foreach ($this->phpFiles($this->modulesRoot().DIRECTORY_SEPARATOR.'Identity') as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/table\([\'"]patient_(profiles|demographic_revisions)/',
                $contents,
                $file.' Identity must not read or write Patients tables.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/table\([\'"](doctor_profiles|specialties)/',
                $contents,
                $file.' Identity must not read or write Doctors tables.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/table\([\'"]verification_(cases|documents|decisions)/',
                $contents,
                $file.' Identity must not read or write Verification tables.',
            );
        }
    }

    #[Test]
    public function patients_does_not_query_doctors_tables(): void
    {
        foreach ($this->phpFiles($this->modulesRoot().DIRECTORY_SEPARATOR.'Patients') as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/table\([\'"](doctor_profiles|specialties)/',
                $contents,
                $file.' Patients must not read or write Doctors tables.',
            );
        }
    }

    #[Test]
    public function doctors_does_not_access_foreign_module_persistence(): void
    {
        foreach ($this->phpFiles($this->modulesRoot().DIRECTORY_SEPARATOR.'Doctors') as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/table\([\'"](?!doctor_profiles|specialties)[a-z_]+/',
                $contents,
                $file.' Doctors must not query another module\'s tables.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/use Modules\\\\(Patients|Auth|Verification)\\\\/',
                $contents,
                $file.' Doctors must not import Patients, Auth, or Verification types.',
            );
        }
    }

    #[Test]
    public function other_modules_do_not_query_doctors_tables(): void
    {
        foreach (['Platform', 'Audit', 'Identity', 'Auth', 'Access', 'Patients', 'Verification', 'Admin'] as $module) {
            foreach ($this->phpFiles($this->modulesRoot().DIRECTORY_SEPARATOR.$module) as $file) {
                $contents = (string) file_get_contents($file);

                $this->assertDoesNotMatchRegularExpression(
                    '/table\([\'"](doctor_profiles|specialties)/',
                    $contents,
                    $file.' '.$module.' must not read or write Doctors tables.',
                );
            }
        }
    }

    #[Test]
    public function verification_ownership_has_not_leaked_into_doctors(): void
    {
        foreach ($this->phpFiles($this->modulesRoot().DIRECTORY_SEPARATOR.'Doctors') as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/table\([\'"]verification_(cases|documents|decisions|upload_intents)/',
                $contents,
                $file.' Doctors must not query Verification tables.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/verification_(cases|documents|decisions|upload_intents)|doctor_verification_documents|SubmitVerificationDocuments/',
                $contents,
                $file.' Verification cases, documents, and decisions are not owned by Doctors.',
            );
        }

        $projection = $this->modulesRoot().DIRECTORY_SEPARATOR.'Doctors/app/Support/DoctorApplicantProjection.php';
        $projectionContents = (string) file_get_contents($projection);
        $this->assertStringNotContainsString('national_id', $projectionContents);
        $this->assertStringNotContainsString('hmac', $projectionContents);
        $this->assertStringNotContainsString('key_version', $projectionContents);

        $reviewerProjection = $this->modulesRoot().DIRECTORY_SEPARATOR.'Doctors/app/Support/DoctorReviewerProjection.php';
        $reviewerProjectionContents = (string) file_get_contents($reviewerProjection);
        $this->assertDoesNotMatchRegularExpression("/'(national_id|hmac|key_version|syndicate_number|phone)'/", $reviewerProjectionContents);

        $routes = (string) file_get_contents(dirname(__DIR__, 3).'/routes/api.php');
        $this->assertMatchesRegularExpression(
            '/DoctorVerificationController::class, [\'"]submit[\'"]/',
            $routes,
        );
        $this->assertMatchesRegularExpression(
            '/DoctorVerificationController::class, [\'"]status[\'"]/',
            $routes,
        );
        $this->assertStringContainsString('admin/verification-cases', $routes);
        $this->assertMatchesRegularExpression(
            '/AdminVerificationController::class, [\'"]index[\'"]/',
            $routes,
        );
        $this->assertStringContainsString('verification-uploads', $routes);
        $this->assertMatchesRegularExpression(
            '/DoctorVerificationUploadController::class, [\'"]create[\'"]/',
            $routes,
        );
        $this->assertStringContainsString('verification-review-files', $routes);
    }

    #[Test]
    public function verification_does_not_query_foreign_persistence(): void
    {
        foreach ($this->phpFiles($this->modulesRoot().DIRECTORY_SEPARATOR.'Verification') as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/table\([\'"](doctor_profiles|specialties|patient_profiles|patient_demographic_revisions)/',
                $contents,
                $file.' Verification must not query Doctors or Patients tables.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/use Modules\\\\(Patients|Auth|Clinical|Pharmacies|Clinics)\\\\/',
                $contents,
                $file.' Verification must not import Patients, Auth, or clinical persistence types.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/use Modules\\\\Doctors\\\\Services\\\\Persistence\\\\/',
                $contents,
                $file.' Verification must reach Doctors only through public services.',
            );
        }
    }

    #[Test]
    public function admin_must_consume_public_verification_services(): void
    {
        $admin = $this->modulesRoot().DIRECTORY_SEPARATOR.'Admin';
        $this->assertDirectoryExists($admin);

        foreach ($this->phpFiles($admin) as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/table\([\'"]verification_(cases|documents|decisions|upload_intents)/',
                $contents,
                $file.' Admin must call Verification public services rather than query verification tables.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/table\([\'"](doctor_profiles|specialties|patient_profiles|patient_demographic_revisions)/',
                $contents,
                $file.' Admin must not query Doctors or Patients persistence.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/use Modules\\\\(Doctors|Patients|Clinical|Appointments|Prescriptions|Labs|Pharmacies|Clinics)\\\\/',
                $contents,
                $file.' Admin verification must not import clinical or Doctors/Patients modules.',
            );
        }

        $catalog = (string) file_get_contents(dirname(__DIR__, 3).'/../../docs/architecture/module-catalog.md');
        $this->assertStringContainsString('Admin work-queue UI calls `VerificationService`', $catalog);
        $this->assertStringContainsString('`VerificationService`', $catalog);
        $this->assertStringContainsString('`VerificationDocumentService`', $catalog);
        $this->assertStringContainsString('`DoctorReviewerService`', $catalog);
        $this->assertStringContainsString('does not emit `admin.verification_decided`', $catalog);
        $this->assertStringContainsString('React Admin verification UI remains deferred', $catalog);
        $this->assertStringNotContainsString('READY_TO_MERGE', $catalog);
    }

    #[Test]
    public function platform_contains_no_doctors_business_logic(): void
    {
        $platform = $this->modulesRoot().DIRECTORY_SEPARATOR.'Platform';

        foreach ($this->phpFiles($platform) as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('doctor_profiles', $contents);
            $this->assertStringNotContainsString('verification_status', $contents);
            $this->assertStringNotContainsString('syndicate_number', $contents);
            $this->assertStringNotContainsString('verification_cases', $contents);
            $this->assertStringNotContainsString('verification_documents', $contents);
            $this->assertStringNotContainsString('verification_decisions', $contents);
            $this->assertStringNotContainsString('verification_upload_intents', $contents);
            $this->assertDoesNotMatchRegularExpression(
                '/\bspecialt(y|ies)\b/i',
                $contents,
                $file.' Platform must remain business-generic.',
            );
        }
    }

    #[Test]
    public function doctors_module_catalog_peak_classification_is_sensitive(): void
    {
        $catalog = dirname(__DIR__, 3).'/../../docs/architecture/module-catalog.md';
        $contents = (string) file_get_contents($catalog);

        $this->assertMatchesRegularExpression(
            '/^\| `Doctors` \| 02 \| Backend \+ clinical \| sensitive \|/m',
            $contents,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\| `Doctors` \| 02 \| Backend \+ clinical \| personal \|/m',
            $contents,
        );
        $this->assertMatchesRegularExpression(
            '/^## `Doctors`.+\*\*Classification:\*\* sensitive\./ms',
            $contents,
        );
        $this->assertStringContainsString('`doctor.profile_created`', $contents);
        $this->assertStringContainsString('personal identifier-only', $contents);
        $this->assertStringContainsString('`specialties`', $contents);
    }

    #[Test]
    public function verification_module_catalog_peak_classification_is_sensitive(): void
    {
        $catalog = dirname(__DIR__, 3).'/../../docs/architecture/module-catalog.md';
        $contents = (string) file_get_contents($catalog);

        $this->assertMatchesRegularExpression(
            '/^\| `Verification` \| 02 \| Backend \+ security \| sensitive \|/m',
            $contents,
        );
        $this->assertMatchesRegularExpression(
            '/^## `Verification`.+\*\*Classification:\*\* sensitive\./ms',
            $contents,
        );
        $this->assertStringContainsString('`verification_cases`', $contents);
        $this->assertStringContainsString('`verification_documents`', $contents);
        $this->assertStringContainsString('`verification_decisions`', $contents);
        $this->assertStringContainsString('`verification_upload_intents`', $contents);
        $this->assertStringContainsString('`doctor.verification_submitted`', $contents);
        $this->assertStringContainsString('`doctor.verification_decided`', $contents);
        $this->assertStringContainsString('`verification.upload_completed`', $contents);
        $this->assertStringContainsString('ENGINEERING_DEFAULT', $contents);
        $this->assertStringContainsString('DoctorApplicantService', $contents);
        $this->assertStringContainsString('DoctorReviewerService', $contents);
        $this->assertStringContainsString('Submit HTTP is compact', $contents);
        $this->assertStringContainsString('DisabledTrustedDocumentEvidenceIssuer', $contents);
        $this->assertStringContainsString('ProcessingTrustedDocumentEvidenceIssuer', $contents);
        $this->assertStringContainsString('frozen after submission', $contents);
        $this->assertStringContainsString('assignment-gated', $contents);
        $this->assertStringNotContainsString('READY_TO_MERGE', $contents);
    }

    #[Test]
    public function verification_document_registration_is_fail_closed(): void
    {
        $provider = (string) file_get_contents(
            $this->modulesRoot().DIRECTORY_SEPARATOR.'Verification/app/Providers/VerificationServiceProvider.php',
        );
        $this->assertStringContainsString('DisabledTrustedDocumentEvidenceIssuer::class', $provider);
        $this->assertStringContainsString('TrustedDocumentEvidenceIssuer::class', $provider);
        $this->assertStringContainsString('ProcessingTrustedDocumentEvidenceIssuer::class', $provider);
        $this->assertDoesNotMatchRegularExpression(
            '/TrustedDocumentEvidenceIssuer::class,\s*ProcessingTrustedDocumentEvidenceIssuer::class/',
            $provider,
        );

        $controller = (string) file_get_contents(
            $this->modulesRoot().DIRECTORY_SEPARATOR.'Verification/app/Http/Controllers/DoctorVerificationController.php',
        );
        $this->assertStringNotContainsString('registerValidatedMetadata', $controller);
        $this->assertStringNotContainsString('TrustedDocumentEvidence', $controller);
        $this->assertStringNotContainsString('reviewSafeMetadata', $controller);

        $uploadController = (string) file_get_contents(
            $this->modulesRoot().DIRECTORY_SEPARATOR.'Verification/app/Http/Controllers/DoctorVerificationUploadController.php',
        );
        $this->assertStringNotContainsString('TrustedDocumentEvidence', $uploadController);
        $this->assertStringNotContainsString('registerValidatedMetadata', $uploadController);
        $this->assertStringNotContainsString('ProcessingTrustedDocumentEvidenceIssuer', $uploadController);

        $service = (string) file_get_contents(
            $this->modulesRoot().DIRECTORY_SEPARATOR.'Verification/app/Services/VerificationDocumentService.php',
        );
        $this->assertStringNotContainsString('account_type === AccountType::Admin', $service);
        $this->assertStringNotContainsString('attributedApplicantId', $service);
        $this->assertDoesNotMatchRegularExpression(
            '/function registerValidatedMetadata\(\s*ActorContext/',
            $service,
        );
        $this->assertMatchesRegularExpression(
            '/function registerValidatedMetadata\(\s*TrustedDocumentEvidence\s+\$evidence\s*\)/',
            $service,
        );
        $this->assertStringContainsString('findById($case->applicantId', $service);
        $this->assertStringNotContainsString('temporaryUrl', $service);
        $this->assertStringContainsString('ReviewerDocumentUrlSigner', $service);
        $this->assertStringContainsString('openStream', $service);
        $this->assertStringContainsString('trustedRef()', $service);
        $this->assertStringNotContainsString('file_get_contents', $service);

        $download = (string) file_get_contents(
            $this->modulesRoot().DIRECTORY_SEPARATOR.'Verification/app/Support/ReviewerDocumentStreamResponse.php',
        );
        $this->assertStringContainsString('fread', $download);
        $this->assertStringContainsString('attachment', $download);
        $this->assertStringContainsString('nosniff', $download);
        $this->assertStringNotContainsString('file_get_contents', $download);
        $this->assertStringNotContainsString('temporaryUrl', $download);
        $this->assertStringNotContainsString('Redirect', $download);

        $routes = (string) file_get_contents(dirname(__DIR__, 3).'/routes/api.php');
        $this->assertStringContainsString('verification-review-files', $routes);
        $this->assertMatchesRegularExpression(
            '/ReviewerDocumentDownloadController::class, [\'"]show[\'"]/',
            $routes,
        );
    }

    #[Test]
    public function scanner_and_storage_adapters_stay_generic_and_stream_bound(): void
    {
        $scanContract = (string) file_get_contents(
            $this->modulesRoot().DIRECTORY_SEPARATOR.'Platform/app/Contracts/ScanObject.php',
        );
        $this->assertStringContainsString('scanStream', $scanContract);
        $this->assertStringNotContainsString('temporaryUrl', $scanContract);
        $this->assertDoesNotMatchRegularExpression('/function scan\(/', $scanContract);

        $clamd = (string) file_get_contents(
            $this->modulesRoot().DIRECTORY_SEPARATOR.'Platform/app/Services/Adapters/ClamdScanObject.php',
        );
        $this->assertStringContainsString('nINSTREAM', $clamd);
        $this->assertStringContainsString("'stream: OK'", $clamd);
        $this->assertStringNotContainsString("\$line === 'OK'", $clamd);
        $this->assertStringNotContainsString("str_ends_with(\$line, 'OK')", $clamd);
        $this->assertStringNotContainsString('Modules\\Doctors', $clamd);
        $this->assertStringNotContainsString('Modules\\Patients', $clamd);
        $this->assertStringNotContainsString('Modules\\Verification', $clamd);
        $this->assertStringNotContainsString('temporaryUrl', $clamd);

        $s3 = (string) file_get_contents(
            $this->modulesRoot().DIRECTORY_SEPARATOR.'Platform/app/Services/ObjectStorage/S3StoreObject.php',
        );
        $this->assertStringNotContainsString('Modules\\Doctors', $s3);
        $this->assertStringNotContainsString('professional_id', $s3);
        $this->assertStringContainsString("'IfNoneMatch' => '*'", $s3);
        $this->assertStringContainsString('isConditionalConflict', $s3);
        $this->assertStringContainsString('is_callable([$client, \'copyObject\'])', $s3);
        $this->assertStringNotContainsString("method_exists(\$client, 'copyObject')", $s3);
        $this->assertStringNotContainsString('$this->disk->copy(', $s3);

        $issuerPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'Support/TestingTrustedDocumentEvidenceIssuer.php';
        $this->assertFileExists($issuerPath);
        $this->assertFileDoesNotExist(
            $this->modulesRoot().DIRECTORY_SEPARATOR.'Verification/app/Services/Adapters/TestingTrustedDocumentEvidenceIssuer.php',
        );
    }

    #[Test]
    public function committed_database_tests_truncate_after_each_case(): void
    {
        $file = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'CommittedDatabaseTestCase.php';
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString('function tearDown', $contents);
        $this->assertStringContainsString('truncateTablesForAllConnections', $contents);
        $this->assertStringNotContainsString("'outbox_events'", $contents);
        $this->assertStringNotContainsString("'audit_events'", $contents);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $basePath): array
    {
        if (! is_dir($basePath)) {
            return [];
        }

        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath)),
            '/\.php$/',
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function modulesRoot(): string
    {
        return dirname(__DIR__, 3).'/Modules';
    }
}
