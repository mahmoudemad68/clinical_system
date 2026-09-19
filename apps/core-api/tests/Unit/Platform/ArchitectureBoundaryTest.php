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
                '/^use Modules\\\\(Auth|Identity|Access|Audit|Patients|Doctors)\\\\/m',
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
                '/use Modules\\\\(Patients|Auth)\\\\/',
                $contents,
                $file.' Doctors must not import Patients or Auth types.',
            );
        }
    }

    #[Test]
    public function other_modules_do_not_query_doctors_tables(): void
    {
        foreach (['Platform', 'Audit', 'Identity', 'Auth', 'Access', 'Patients'] as $module) {
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
                '/verification_(cases|documents|decisions)|doctor_verification_documents|SubmitVerificationDocuments/',
                $contents,
                $file.' Verification cases, documents, and decisions are not owned by Doctors.',
            );
        }

        $routes = (string) file_get_contents(dirname(__DIR__, 3).'/routes/api.php');
        $this->assertStringNotContainsString('verification-submissions', $routes);
        $this->assertStringNotContainsString('verification-status', $routes);
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
        $this->assertStringContainsString(
            '`doctor.profile_created` remains a personal identifier-only projection',
            $contents,
        );
        $this->assertStringContainsString('`specialties`', $contents);
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
