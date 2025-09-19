#!/usr/bin/env php
<?php

/**
 * LLM Directory Formatter
 *
 * A command-line tool to recursively read files in a directory, format them
 * into a single text block for consumption by an LLM, and either print to
 * stdout or copy to the clipboard.
 *
 * Features:
 * - Recursively scans a directory.
 * - Optionally ignores files based on .gitignore rules, supporting the full pattern specification.
 * - Optionally ignores files based on custom command-line patterns.
 * - Skips binary files using the 'file' command-line utility for reliability.
 * - Includes the MIME type for each file in the output.
 * - Can output directly to the terminal or copy to the system clipboard
 * using the OSC 52 escape sequence.
 */

// --- Main Execution ---

try {
    $config = parse_options();

    if ($config['help']) {
        show_usage();
        exit(0);
    }

    $finalOutput = build_output_recursive(
        $config['startPath'],
        $config['startPath'],
        $config['cliIgnorePatterns'],
        $config['useGitignore']
    );

    if (empty(trim($finalOutput))) {
        fwrite(STDERR, "No files were found to process with the given criteria.\n");
        exit(0);
    }

    if ($config['copyToClipboard']) {
        // OSC 52 escape sequence allows terminal apps to access the system clipboard.
        // It requires a compatible terminal (e.g., iTerm2, Kitty, WezTerm, Windows Terminal).
        $base64Content = base64_encode($finalOutput);
        echo "\033]52;c;{$base64Content}\a";
        fwrite(STDERR, "Formatted content has been copied to the clipboard.\n");
    } else {
        echo $finalOutput;
    }

} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}


// --- Core Functions ---

/**
 * Parses command-line arguments into a configuration array.
 * @return array The parsed configuration.
 * @throws Exception If the specified directory is invalid.
 */
function parse_options(): array {
    $short_opts = "d:gi:ch";
    $long_opts = ["dir:", "use-gitignore", "ignore:", "copy", "help"];
    $options = getopt($short_opts, $long_opts);

    if (isset($options['h']) || isset($options['help'])) {
        return ['help' => true];
    }

    $startPath = $options['d'] ?? ($options['dir'] ?? '.');
    $realStartPath = realpath($startPath);

    if ($realStartPath === false) {
        throw new Exception("The specified directory '{$startPath}' does not exist.");
    }

    $ignoreInput = $options['i'] ?? ($options['ignore'] ?? '');

    return [
        'startPath' => $realStartPath,
        'useGitignore' => isset($options['g']) || isset($options['use-gitignore']),
        'copyToClipboard' => isset($options['c']) || isset($options['copy']),
        'cliIgnorePatterns' => !empty($ignoreInput) ? array_map('trim', explode(',', $ignoreInput)) : [],
        'help' => false,
    ];
}

/**
 * Displays the script's usage instructions.
 */
function show_usage() {
    $scriptName = basename(__FILE__);
    echo <<<USAGE
Usage: php {$scriptName} [options]

Formats the contents of a directory for consumption by an LLM.

Options:
  -d, --dir <path>         The directory to process.
                           (Default: current directory)

  -g, --use-gitignore      Exclude files and directories specified by any found
                           .gitignore files.

  -i, --ignore <patterns>  A comma-separated list of glob patterns to ignore.
                           (e.g., "*.log,build/*,vendor")

  -c, --copy               Copy the output to the system clipboard instead of
                           printing it to the terminal.

  -h, --help               Display this help message.

Example:
  php {$scriptName} -d ./my-project -g -i "dist/*,*.env" -c

USAGE;
}

/**
 * Recursively traverses directories and builds the formatted output string.
 *
 * @param string $dir The current directory to process.
 * @param string $basePath The root directory of the entire operation.
 * @param array $cliIgnorePatterns Patterns from the --ignore flag.
 * @param bool $useGitignore Whether to respect .gitignore files.
 * @return string The formatted output.
 */
function build_output_recursive(string $dir, string $basePath, array $cliIgnorePatterns, bool $useGitignore): string {
    $output = '';
    $items = @scandir($dir);

    if ($items === false) {
        fwrite(STDERR, "Warning: Could not read directory {$dir}\n");
        return '';
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullPath = $dir . DIRECTORY_SEPARATOR . $item;

        if (should_ignore($fullPath, $basePath, $cliIgnorePatterns, $useGitignore)) {
            continue;
        }

        if (is_dir($fullPath)) {
            $output .= build_output_recursive($fullPath, $basePath, $cliIgnorePatterns, $useGitignore);
        } elseif (is_file($fullPath) && is_readable($fullPath)) {
            if (is_binary_file($fullPath)) {
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $fullPath);
                fwrite(STDERR, "Skipping binary file: {$relativePath}\n");
                continue;
            }

            $content = file_get_contents($fullPath);
            $mimeType = @mime_content_type($fullPath) ?: 'application/octet-stream';
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $fullPath);
            
            $output .= "--- BEGIN FILE: {$relativePath} (MIME: {$mimeType}) ---\n";
            $output .= $content;
            $output .= "\n--- END FILE: {$relativePath} ---\n\n";
        }
    }
    return $output;
}

/**
 * Determines if a file is binary using the 'file' command-line utility.
 * Falls back to a simple null-byte check if the 'file' command is not available.
 *
 * @param string $filePath The absolute path to the file.
 * @return bool True if the file is likely binary.
 */
function is_binary_file(string $filePath): bool {
    // escapeshellarg is crucial for security and handling paths with special characters.
    $command = 'file -b --mime-encoding ' . escapeshellarg($filePath);
    $encoding = @shell_exec($command);

    if ($encoding === null || $encoding === false) {
        // Fallback to null byte check if `file` command fails or is not available.
        $content = file_get_contents($filePath);
        return mb_strpos($content, "\0") !== false;
    }

    // The command's output includes a newline, so we trim it.
    return trim($encoding) === 'binary';
}

/**
 * Converts a gitignore-style glob pattern to a regular expression.
 *
 * @param string $pattern The glob pattern.
 * @return string The resulting regular expression.
 */
function gitignore_glob_to_regex(string $pattern): string {
    $pattern = preg_quote($pattern, '#');
    // Temporarily replace '**' with a placeholder to distinguish from '*'.
    $pattern = str_replace('\\*\\*', '##GLOBSTAR##', $pattern);
    // Convert '*' to match anything except a slash.
    $pattern = str_replace('\\*', '[^/]*', $pattern);
    // Convert '?' to match any single character except a slash.
    $pattern = str_replace('\\?', '[^/]', $pattern);
    // Convert the globstar placeholder back to its regex equivalent '.*'.
    $pattern = str_replace('##GLOBSTAR##', '.*', $pattern);
    return '#^' . $pattern . '$#';
}


/**
 * Determines if a file or directory should be ignored.
 *
 * This function checks against CLI patterns and .gitignore patterns. The rule
 * from the most specific .gitignore file (closest to the file path) that
 * matches will win. The last matching rule in a given file wins.
 *
 * @param string $fullPath The absolute path to the file/directory.
 * @param string $basePath The root directory of the operation.
 * @param array $cliIgnorePatterns Patterns from the --ignore flag.
 * @param bool $useGitignore Whether to respect .gitignore files.
 * @return bool True if the item should be ignored.
 */
function should_ignore(string $fullPath, string $basePath, array $cliIgnorePatterns, bool $useGitignore): bool {
    $relativePath = ltrim(str_replace($basePath, '', $fullPath), DIRECTORY_SEPARATOR);

    // Ignore this script itself
    if (realpath($fullPath) === realpath(__FILE__)) return true;

    // Default ignore for .git directory
    if (fnmatch('.git', $relativePath) || fnmatch('.git/*', $relativePath)) return true;

    // Check against command-line ignore patterns first
    foreach ($cliIgnorePatterns as $pattern) {
        if (fnmatch($pattern, $relativePath, FNM_PATHNAME)) {
            return true;
        }
    }

    if (!$useGitignore) {
        return false;
    }

    // Check against .gitignore files, from the base path down to the file's directory.
    // The last matching rule wins. A file cannot be re-included if a parent directory is excluded.
    $decision = null; // null = undecided, true = ignore, false = include
    
    $dirsToCheck = [$basePath];
    $cumulativePath = $basePath;
    $pathParts = explode(DIRECTORY_SEPARATOR, dirname($relativePath));
    if ($pathParts[0] !== '.') { // Avoid adding './' for files in the root
        foreach($pathParts as $part) {
            if (empty($part)) continue;
            $cumulativePath .= DIRECTORY_SEPARATOR . $part;
            $dirsToCheck[] = $cumulativePath;
        }
    }


    foreach ($dirsToCheck as $dir) {
        $gitignoreFile = $dir . DIRECTORY_SEPARATOR . '.gitignore';
        if (file_exists($gitignoreFile)) {
            $patterns = file($gitignoreFile, FILE_IGNORE_NEW_LINES);
            $pathRelativeToGitignore = ltrim(str_replace($dir, '', $fullPath), DIRECTORY_SEPARATOR);

            foreach ($patterns as $pattern) {
                // 1. Handle trailing spaces. Git ignores them unless escaped with a backslash.
                if (substr($pattern, -2) === '\ ') {
                    $pattern = substr($pattern, 0, -2) . ' ';
                } else {
                    $pattern = rtrim($pattern);
                }

                // 2. A blank line matches no files.
                if (empty($pattern)) continue;

                // 3. A line starting with # serves as a comment. Handle escaped hash.
                if ($pattern[0] === '#' && strpos($pattern, '\#') !== 0) continue;
                if (strpos($pattern, '\#') === 0) $pattern = substr($pattern, 1);
                
                // 4. Handle negation vs. escaped exclamation mark.
                $isNegated = false;
                if ($pattern[0] === '!') {
                    $isNegated = true;
                    $pattern = substr($pattern, 1);
                } elseif (strpos($pattern, '\!') === 0) {
                    $pattern = substr($pattern, 1);
                }

                if (empty($pattern)) continue;

                $match = false;
                
                if (strpos($pattern, '/') === false) {
                    // Case 1: No slash in pattern. Match against any path component.
                    foreach (explode(DIRECTORY_SEPARATOR, $pathRelativeToGitignore) as $component) {
                        if (fnmatch($pattern, $component)) {
                            $match = true;
                            break;
                        }
                    }
                } else {
                    // Case 2: Slash in pattern. Match relative to the .gitignore file's location.
                    $anchoredPattern = ltrim($pattern, '/');

                    if (strpos($anchoredPattern, '**') !== false) {
                        // Subcase 2a: Pattern has a globstar. Use regex.
                        $regex = gitignore_glob_to_regex($anchoredPattern);
                        if (preg_match($regex, $pathRelativeToGitignore)) {
                            $match = true;
                        }
                    } else {
                        // Subcase 2b: Standard path-based glob.
                        $basePattern = rtrim($anchoredPattern, '/');
                        if (fnmatch($basePattern, $pathRelativeToGitignore, FNM_PATHNAME)) {
                             $match = true;
                        } elseif (is_dir($dir . DIRECTORY_SEPARATOR . $basePattern) && strpos($pathRelativeToGitignore, $basePattern . '/') === 0) {
                             $match = true;
                        }
                    }
                }
                
                if ($match) {
                    $decision = !$isNegated;
                }
            }
        }
    }

    return $decision === true;
}

