<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Auth\Application\RegisterAccountCoordinator;
use App\Modules\Identity\Application\DisableIdentityCoordinator;
use App\Modules\Platform\Application\Coordinators\ApprovedCoordinators;
use App\Modules\Platform\Application\Outbox\OutboxConsumer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Architecture tests that deptrac does not cover: coordinator allow-list,
 * Domain not importing Illuminate by source, consumers staying inside Platform.
 */
final class ArchitectureBoundaryTest extends TestCase
{
    #[Test]
    public function phase_01_lists_the_registration_and_disable_coordinators(): void
    {
        $this->assertContains(
            RegisterAccountCoordinator::class,
            ApprovedCoordinators::classes(),
        );
        $this->assertContains(
            DisableIdentityCoordinator::class,
            ApprovedCoordinators::classes(),
        );
    }

    #[Test]
    public function coordinator_classes_must_be_on_the_allow_list(): void
    {
        $allowed = ApprovedCoordinators::classes();
        $found = [];

        foreach ($this->phpFiles(basePath: $this->modulesRoot()) as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/^final class (\w+Coordinator)\b/m', $contents, $match) !== 1) {
                continue;
            }

            if ($match[1] === 'ApprovedCoordinators') {
                continue;
            }

            if (preg_match('/^namespace (App\\\\Modules\\\\[^;]+);/m', $contents, $ns) !== 1) {
                continue;
            }

            $found[] = $ns[1].'\\'.$match[1];
        }

        $this->assertSame(
            [],
            array_values(array_diff($found, $allowed)),
            'A coordinator class must be listed in ApprovedCoordinators before it can call another module.',
        );
    }

    #[Test]
    public function domain_sources_do_not_import_the_framework(): void
    {
        foreach ($this->phpFiles($this->modulesRoot()) as $file) {
            if (! str_contains($file, DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/^use (Illuminate|Laravel)\\\\/m',
                $contents,
                $file.' Domain must not import framework types.',
            );
        }
    }

    #[Test]
    public function outbox_consumers_do_not_import_another_modules_eloquent_models(): void
    {
        foreach ($this->phpFiles($this->modulesRoot()) as $file) {
            $contents = (string) file_get_contents($file);

            if (! str_contains($contents, 'implements OutboxConsumer')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/use App\\\\Modules\\\\(?!Platform\\\\).*\\\\Infrastructure\\\\Persistence\\\\/',
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
        return dirname(__DIR__, 3).'/app/Modules';
    }
}
