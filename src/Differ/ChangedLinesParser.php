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

namespace Infection\Differ;

use function explode;
use Infection\CannotBeInstantiated;
use Infection\Differ\Throwable\InvalidDiffFormat;
use Infection\Framework\Str;
use InvalidArgumentException;
use const PHP_INT_MAX;
use const PREG_UNMATCHED_AS_NULL;
use function Safe\preg_match;
use function sprintf;
use function str_starts_with;
use Symfony\Component\Filesystem\Path;
use Webmozart\Assert\Assert;

/**
 * Reads the lines a unified diff adds or modifies, e.g. the output of "git diff --unified=0".
 *
 * @internal
 */
final class ChangedLinesParser
{
    use CannotBeInstantiated;

    private const string DIFF_LINE_REGEX = '/diff.*a\/.*\sb\/(?<filePath>.*)/';

    private const string DIFF_LINE_PATH_KEY = 'filePath';

    private const string HUNK_HEADER_REGEX = '/\A@@ -[0-9]+(?:,(?<old>[0-9]+))? \+(?<start>[0-9]+)(?:,(?<new>[0-9]+))? @@(?: .*)?\z/';

    /**
     * @param string[] $lines
     * @param string $baseDirectory Directory the paths of the diff are relative to.
     *
     * @return array<non-empty-string, list<ChangedLinesRange>> The changed lines ranges by absolute file path.
     */
    public static function parse(array $lines, string $baseDirectory): array
    {
        $filePath = null;
        $result = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'diff ')) {
                $filePath = Path::join($baseDirectory, self::parseFilePathFromLine($line));
                Assert::stringNotEmpty($filePath);
            } elseif (str_starts_with($line, '@@ ')) {
                Assert::notNull(
                    $filePath,
                    sprintf(
                        'The following hunk line comes before any diff line: "%s"',
                        $line,
                    ),
                );

                $changedLinesRange = self::parseChangedLinesRangeFromLine($line);

                if ($changedLinesRange !== null) {
                    $result[$filePath][] = $changedLinesRange;
                }
            } else {
                self::assertNoContext($line);
            }
        }

        return $result;
    }

    /**
     * @throws InvalidDiffFormat
     *
     * @return array<non-empty-string, list<ChangedLinesRange>>
     */
    public static function parseDiffFile(string $contents, string $baseDirectory): array
    {
        $lines = explode("\n", Str::toUnixLineEndings($contents));

        try {
            // Keep the parse first: it has more accurate diagnostics upon failure.
            $ranges = self::parse($lines, $baseDirectory);
            self::validateDiffFile($lines);

            return $ranges;
        } catch (InvalidArgumentException $exception) {
            throw InvalidDiffFormat::fromParsingFailure($exception);
        }
    }

    /**
     * @param list<string> $lines
     */
    private static function validateDiffFile(array $lines): void
    {
        $hasFileHeader = false;
        $hunk = null;
        $removed = 0;
        $added = 0;

        foreach ($lines as $line) {
            if ($hunk !== null) {
                if (str_starts_with($line, '-')) {
                    ++$removed;

                    continue;
                }

                if (str_starts_with($line, '+')) {
                    ++$added;

                    continue;
                }

                if ($line === '\No newline at end of file') {
                    continue;
                }

                self::assertHunkCounts($hunk, $removed, $added);

                $hunk = null;
                $removed = 0;
                $added = 0;
            }

            if (str_starts_with($line, 'diff --git ')) {
                $hasFileHeader = true;

                continue;
            }

            Assert::true(
                $hasFileHeader || $line === '',
                'Expected a "diff --git" file header before the diff content.',
            );

            if (str_starts_with($line, '@@')) {
                $hunk = $line;
            }
        }

        if ($hunk !== null) {
            self::assertHunkCounts($hunk, $removed, $added);
        }
    }

    private static function assertNoContext(string $line): void
    {
        Assert::false(
            str_starts_with($line, ' '),
            sprintf(
                'Expected a diff without context lines, e.g. the output of "git diff --unified=0", but found the following context line: "%s"',
                $line,
            ),
        );
    }

    private static function assertHunkCounts(string $hunk, int $removed, int $added): void
    {
        [, $expectedRemoved, $expectedAdded] = self::parseHunkHeader($hunk);

        Assert::true(
            $removed === $expectedRemoved && $added === $expectedAdded,
            sprintf(
                'Hunk "%s" declares %d removed and %d added lines, but contains %d removed and %d added lines.',
                $hunk,
                $expectedRemoved,
                $expectedAdded,
                $removed,
                $added,
            ),
        );
    }

    private static function parseFilePathFromLine(string $line): string
    {
        preg_match(self::DIFF_LINE_REGEX, $line, $matches);

        Assert::keyExists(
            $matches,
            self::DIFF_LINE_PATH_KEY,
            sprintf(
                'Source file can not be found in the following diff line: "%s"',
                $line,
            ),
        );

        return $matches[self::DIFF_LINE_PATH_KEY];
    }

    /**
     * Examples of possible forms for the input line:
     *
     * - '@@ -10,5 +12,7 @@ ...': lines added and removed, here 5 lines removed at L10 in the old file and 7 lines added from L12 in the new file
     * - '@@ -10,0 +11,5 @@ ...': only lines added, 0 lines from the old file at L10, 5 lines added starting at L11 in new file
     *
     * Check the test for more examples.
     */
    private static function parseChangedLinesRangeFromLine(string $line): ?ChangedLinesRange
    {
        [$startLine, , $newCount] = self::parseHunkHeader($line);

        if ($newCount === 0) {
            return null;
        }

        return ChangedLinesRange::forRange($startLine, $newCount);
    }

    /**
     * @return array{non-negative-int, non-negative-int, non-negative-int}
     */
    private static function parseHunkHeader(string $line): array
    {
        $matched = preg_match(
            self::HUNK_HEADER_REGEX,
            $line,
            $matches,
            PREG_UNMATCHED_AS_NULL,
        );

        Assert::same($matched, 1, sprintf('Invalid hunk header "%s".', $line));
        Assert::notNull($matches, 'A matched hunk header must have captures.');

        $startLine = (int) $matches['start'];
        $removed = (int) ($matches['old'] ?? '1');
        $added = (int) ($matches['new'] ?? '1');

        Assert::natural($startLine, 'The destination line number must be non-negative.');
        Assert::natural($removed, 'The removed line count must be non-negative.');
        Assert::natural($added, 'The added line count must be non-negative.');

        Assert::true(
            (string) $startLine === $matches['start'] && $startLine <= PHP_INT_MAX - $added,
            sprintf('The destination range in hunk "%s" is too large.', $line),
        );

        return [$startLine, $removed, $added];
    }
}
