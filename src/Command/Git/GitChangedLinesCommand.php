<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace Infection\Command\Git;

use Infection\Command\BaseCommand;
use Infection\Command\Git\Option\BaseOption;
use Infection\Command\Git\Option\FilterOption;
use Infection\Command\Option\ConfigurationOption;
use Infection\Configuration\SourceFilter\ChangedLinesByFileFilter;
use Infection\Configuration\SourceFilter\GitDiffFileFilter;
use Infection\Configuration\SourceFilter\GitDiffFilter;
use Infection\Configuration\SourceFilter\IncompleteGitDiffFilter;
use Infection\Console\IO;
use Infection\Container\Container;
use Infection\Differ\ChangedLinesRange;
use Infection\FileSystem\Locator\FileOrDirectoryNotFound;
use Infection\Git\Throwable\GitDiffFailed;
use Infection\Source\Exception\NoSourceFound;
use Psr\Log\LoggerInterface;
use function Safe\getcwd;
use function sprintf;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;
use Webmozart\Assert\Assert;

/**
 * @internal
 */
final class GitChangedLinesCommand extends BaseCommand
{
    private const string DIFF_FILE_ARGUMENT = 'diff-file';

    public function __construct()
    {
        parent::__construct('git:changed-lines');
    }

    protected function configure(): void
    {
        $this->setDescription(
            'Lists changed line ranges and their paths relative to the current working directory.',
        );
        $this->addArgument(
            self::DIFF_FILE_ARGUMENT,
            InputArgument::OPTIONAL,
            sprintf(
                'Git unified diff without context lines. The file path and paths in the diff are relative to the configuration directory. Cannot be combined with --%s or --%s.',
                BaseOption::NAME,
                FilterOption::NAME,
            ),
        );

        ConfigurationOption::addOption($this);
        BaseOption::addOption($this);
        FilterOption::addOption($this);
    }

    protected function executeCommand(IO $io): bool
    {
        $logger = LoggerFactory::create($io->getOutput());
        $workingDirectory = getcwd();
        $diffFile = self::getDiffFile($io);

        $inputBase = $diffFile === null ? BaseOption::get($io) : null;

        $container = $this->getApplication()->getContainer()->withValues(
            logger: $logger,
            output: $io->getOutput(),
            configFile: ConfigurationOption::get($io),
            sourceFilter: $diffFile === null
                ? new IncompleteGitDiffFilter(FilterOption::get($io), $inputBase)
                : new GitDiffFileFilter($diffFile),
            projectDirectory: $diffFile === null ? null : $workingDirectory,
        );

        if ($diffFile !== null) {
            $sourceFilter = $container->getConfiguration()->sourceFilter->fileFilter;
            Assert::isInstanceOf($sourceFilter, ChangedLinesByFileFilter::class);

            $changedLines = $sourceFilter->changedLinesRangesByFilePath;
        } else {
            $changedLines = self::getChangedLinesFromGit(
                $container,
                $logger,
                $inputBase,
                $workingDirectory,
            );
        }

        self::printChangedLines($changedLines, $workingDirectory, $io);

        return true;
    }

    /**
     * @param non-empty-string|null $inputBase
     * @param non-empty-string $workingDirectory
     *
     * @throws FileOrDirectoryNotFound
     * @throws GitDiffFailed
     * @throws NoSourceFound
     *
     * @return non-empty-array<string, list<ChangedLinesRange>>
     */
    private static function getChangedLinesFromGit(
        Container $container,
        LoggerInterface $logger,
        ?string $inputBase,
        string $workingDirectory,
    ): array {
        $git = $container->getGit();

        if ($inputBase === null) {
            $logger->notice(
                sprintf(
                    'No base found. Using the default base "%s".',
                    $git->getDefaultBase(),
                ),
            );
        }

        $configuration = $container->getConfiguration();
        $sourceFilter = $configuration->sourceFilter->fileFilter;
        Assert::isInstanceOf($sourceFilter, GitDiffFilter::class);

        $logger->notice(
            sprintf(
                'Using the reference "%s".',
                $sourceFilter->base,
            ),
        );

        return $git->getChangedLinesRangesByFilePaths(
            $sourceFilter->value,
            $sourceFilter->base,
            $configuration->source->directories,
            $workingDirectory,
        );
    }

    /**
     * @return non-empty-string|null
     */
    private static function getDiffFile(IO $io): ?string
    {
        $input = $io->getInput();

        /** @var string|null $diffFile */
        $diffFile = $input->getArgument(self::DIFF_FILE_ARGUMENT);

        if ($diffFile === null) {
            return null;
        }

        Assert::false(
            $input->hasParameterOption(['--' . BaseOption::NAME, '--' . FilterOption::NAME], onlyParams: true),
            sprintf(
                'The diff file argument cannot be combined with "--%s" or "--%s".',
                BaseOption::NAME,
                FilterOption::NAME,
            ),
        );
        Assert::stringNotEmpty($diffFile, 'Expected a non-empty path for the diff file argument.');

        return $diffFile;
    }

    /**
     * @param non-empty-array<string, list<ChangedLinesRange>> $changedLines
     */
    private static function printChangedLines(
        array $changedLines,
        string $workingDirectory,
        OutputInterface $output,
    ): void {
        foreach ($changedLines as $file => $fileChangedLines) {
            $relativePath = Path::makeRelative($file, $workingDirectory);

            foreach ($fileChangedLines as $fileChangedLine) {
                $output->writeln(
                    sprintf(
                        '%s: [%s,%s]',
                        $relativePath,
                        $fileChangedLine->startLine,
                        $fileChangedLine->endLine,
                    ),
                );
            }
        }
    }
}
