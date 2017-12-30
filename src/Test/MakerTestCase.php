<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MakerBundle\Test;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class MakerTestCase extends TestCase
{
    private static $currentRootDir;
    private static $flexProjectPath;
    private static $fixturesCachePath;

    /** @var Filesystem */
    private static $fs;

    protected function executeMakerCommand(MakerTestDetails $testDetails)
    {
        self::$currentRootDir = __DIR__.'/../../tests/tmp/current_project';
        self::$flexProjectPath = __DIR__.'/../../tests/tmp/template_project';
        self::$fixturesCachePath = __DIR__.'/../../tests/tmp/cache';
        self::$fs = new Filesystem();

        if (!file_exists(self::$flexProjectPath)) {
            $this->buildFlexProject();
        }

        // puts the project into self::$currentRootDir
        $this->prepareProjectDirectory($testDetails);

        $executableFinder = new PhpExecutableFinder();
        $phpPath = $executableFinder->find(false);
        $process = $this->createProcess(
            sprintf('%s bin/console %s', $phpPath, ($testDetails->getMaker())::getCommandName()),
            self::$currentRootDir
        );
        $process->setTimeout(10);

        // tells the command we are in interactive mode
        $process->setEnv([
            'SHELL_INTERACTIVE' => '1',
        ]);
        $inputStream = new InputStream();
        $userInputs = $testDetails->getInputs();
        // start the command with some input
        if (!empty($userInputs)) {
            $inputStream->write(current($userInputs)."\n");
        }

        $inputStream->onEmpty(function () use ($inputStream, &$userInputs) {
            $nextInput = next($userInputs);
            if (false === $nextInput) {
                $inputStream->close();
            } else {
                $inputStream->write($nextInput."\n");
            }
        });
        $process->setInput($inputStream);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception(sprintf('Running maker command failed: "%s" "%s"', $process->getOutput(), $process->getErrorOutput()));
        }

        $this->assertContains('Success', $process->getOutput(), $process->getErrorOutput());
        $files = $this->getGeneratedPhpFilesFromOutputText($process->getOutput());
        foreach ($files as $file) {
            $process = $this->createProcess(sprintf('php vendor/bin/php-cs-fixer fix --dry-run --diff %s', self::$currentRootDir.'/'.$file), __DIR__.'/../../');
            $process->run();
            $this->assertTrue($process->isSuccessful(), sprintf('File "%s" has a php-cs problem: %s', $file, $process->getOutput()));
        }

        foreach ($testDetails->getPostMakeCommands() as $postCommand) {
            $process = $this->createProcess($postCommand, self::$currentRootDir);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \Exception(sprintf('Error with post command: "%s": "%s" "%s"', $postCommand, $process->getOutput(), $process->getErrorOutput()));
            }
        }

        // if there is a fixtures directory, assume it contains tests
        if ($testDetails->getFixtureFilesPath()) {
            // execute the tests that were moved into the project!
            $process = $this->createProcess(
                // using OUR simple-phpunit for speed (to avoid downloading more deps)
                '../../../vendor/bin/simple-phpunit',
                self::$currentRootDir
            );
            $process->run();
            $this->assertTrue($process->isSuccessful(), "Error while running the PHPUnit tests *in* the project: \n\n".$process->getOutput());
        }
    }

    private function buildFlexProject()
    {
        $process = $this->createProcess('composer create-project symfony/skeleton template_project', dirname(self::$flexProjectPath));
        $this->runProcess($process);

        // processes any changes needed to the Flex project
        $replacements = [
            [
                'filename' => 'config/bundles.php',
                'find' => "Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],",
                'replace' => "Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],\n    Symfony\Bundle\MakerBundle\MakerBundle::class => ['dev' => true],",
            ],
            [
                'filename' => 'composer.json',
                'find' => '"App\\\Tests\\\": "tests/"',
                'replace' => sprintf('"App\\\Tests\\\": "tests/",'."\n".'            "Symfony\\\Bundle\\\MakerBundle\\\": "%s/src/"', __DIR__.'../../../')
            ],
        ];
        $this->processReplacements($replacements, self::$flexProjectPath);

        // fetch a few packages needed for testing
        $process = $this->createProcess('composer require phpunit browser-kit', self::$flexProjectPath);
        $this->runProcess($process);
    }

    private function getGeneratedPhpFilesFromOutputText($output)
    {
        $files = [];
        foreach (explode("\n", $output) as $line) {
            if (false === strpos($line, 'created:')) {
                continue;
            }

            list(, $filename) = explode(':', $line);
            $files[] = trim($filename);
        }

        return $files;
    }

    private function runProcess(Process $process)
    {
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception(sprintf(
                'Error running command: "%s". Output: "%s". Error: "%s"',
                $process->getCommandLine(),
                $process->getOutput(),
                $process->getErrorOutput()
            ));
        }
    }

    private function createProcess($commandLine, $cwd)
    {
        $process = new Process($commandLine, $cwd);
        // avoid 3.x deprecation warnings
        $process->inheritEnvironmentVariables();

        return $process;
    }

    private function prepareProjectDirectory(MakerTestDetails $makerTestDetails)
    {
        if (null !== $makerTestDetails->getFixtureFilesPath() && !file_exists($makerTestDetails->getFixtureFilesPath())) {
            throw new \Exception(sprintf('Cannot find fixtures directory "%s"', $makerTestDetails->getFixtureFilesPath()));
        }

        // initialize the app corresponding to *this* fixtures directory
        // and put it in a cache file so any re-runs are much faster
        $fixturesCacheDir = self::$fixturesCachePath.'/'.$makerTestDetails->getUniqueCacheDirectoryName();
        if (!file_exists($fixturesCacheDir)) {
            self::$fs->mirror(self::$flexProjectPath, $fixturesCacheDir);

            // install any missing dependencies
            $depBuilder = new DependencyBuilder();
            $makerTestDetails->getMaker()->configureDependencies($depBuilder);

            if ($depBuilder->getMissingDependencies()) {
                $process = $this->createProcess(sprintf('composer require %s', implode(' ', $depBuilder->getMissingDependencies())), $fixturesCacheDir);
                $this->runProcess($process);
            }
        }

        self::$fs->remove(self::$currentRootDir);
        self::$fs->mirror($fixturesCacheDir, self::$currentRootDir);

        // re-dump the autoloader so that it's correct for the new directory
        // this is due the directory being moved and Composer storing the
        // path internally in a relative way
        $process = $this->createProcess('composer dump-autoload', self::$currentRootDir);
        $this->runProcess($process);

        if (null !== $makerTestDetails->getFixtureFilesPath()) {
            // move fixture files into directory
            $finder = new Finder();
            $finder->in($makerTestDetails->getFixtureFilesPath())->files();

            foreach ($finder as $file) {
                if ($file->getPath() === $makerTestDetails->getFixtureFilesPath()) {
                    continue;
                }

                self::$fs->copy($file->getPathname(), self::$currentRootDir . '/' . $file->getRelativePathname(), true);
            }
        }

        if ($makerTestDetails->getReplacements()) {
            $this->processReplacements($makerTestDetails->getReplacements(), self::$currentRootDir);
        }
    }

    private function processReplacements(array $replacements, $rootDir)
    {
        foreach ($replacements as $replacement) {
            $path = $rootDir.'/'.$replacement['filename'];
            $contents = file_get_contents($path);
            if (false === strpos($contents, $replacement['find'])) {
                throw new \Exception(sprintf('Could not find "%s" inside "%s"', $replacement['find'], $replacement['filename']));
            }

            file_put_contents($path, str_replace($replacement['find'], $replacement['replace'], $contents));
        }
    }
}