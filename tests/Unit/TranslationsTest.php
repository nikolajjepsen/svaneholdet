<?php

use Symfony\Component\Finder\Finder;

/**
 * Finds every `__()` call with a plain string literal in app/ and
 * resources/views/, keyed by translation key, with the first place it is used.
 *
 * Calls built from variables or concatenation are skipped, since their key
 * is only known at runtime.
 *
 * @return array<string, string>
 */
function translationKeysInSource(string $projectPath): array
{
    $pattern = <<<'REGEX'
        /(?<![\w>:$])__\(\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\$]|\\.)*)")\s*[,)]/s
        REGEX;

    $keys = [];

    $files = Finder::create()
        ->files()
        ->in([$projectPath.'/app', $projectPath.'/resources/views'])
        ->name('*.php')
        ->sortByName();

    foreach ($files as $file) {
        $contents = $file->getContents();

        preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $isSingleQuoted = ($match[1][1] ?? -1) !== -1;
            $key = $isSingleQuoted
                ? strtr($match[1][0], ['\\\\' => '\\', "\\'" => "'"])
                : stripcslashes($match[2][0]);

            $line = substr_count($contents, "\n", 0, $match[0][1]) + 1;
            $keys[$key] ??= $file->getRelativePathname().':'.$line;
        }
    }

    return $keys;
}

/**
 * @return array<string, string>
 */
function danishTranslations(string $projectPath): array
{
    return json_decode((string) file_get_contents($projectPath.'/lang/da.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('has a Danish translation for every __() string', function () {
    $projectPath = dirname(__DIR__, 2);
    $translations = danishTranslations($projectPath);

    $missing = array_diff_key(translationKeysInSource($projectPath), $translations);

    $report = collect($missing)
        ->map(fn (string $location, string $key): string => "  \"{$key}\" (used in {$location})")
        ->implode(PHP_EOL);

    expect($missing)->toBeEmpty('These keys are missing from lang/da.json:'.PHP_EOL.$report);
});

it('keeps lang/da.json sorted by key', function () {
    $keys = array_keys(danishTranslations(dirname(__DIR__, 2)));

    foreach (array_slice($keys, 1) as $index => $key) {
        $previousKey = $keys[$index];

        expect(strcmp($previousKey, $key))->toBeLessThan(
            0,
            "lang/da.json is not sorted: \"{$key}\" belongs before \"{$previousKey}\". Sort the keys alphabetically.",
        );
    }
});
