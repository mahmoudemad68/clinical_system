<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use Modules\Auth\Services\RegisterAccountService;
use Modules\Identity\Services\DisableIdentityService;
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
    public function phase_01_lists_the_registration_and_disable_coordinating_services(): void
    {
        $this->assertContains(
            RegisterAccountService::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            DisableIdentityService::class,
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
                '/^use Modules\\\\(Auth|Identity|Access|Audit)\\\\/m',
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
