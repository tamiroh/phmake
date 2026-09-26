<?php

declare(strict_types=1);

$destination = $argv[1] ?? throw new InvalidArgumentException('Usage: build-phar.php OUTPUT.phar PHP_INTERPRETER');
$interpreter = $argv[2] ?? throw new InvalidArgumentException('An absolute PHP interpreter path is required');
if (!str_starts_with($interpreter, '/') || !is_executable($interpreter) || preg_match('/\s/', $interpreter) === 1) {
    throw new InvalidArgumentException('The PHP interpreter must be an executable absolute path without whitespace');
}

$archive = new Phar($destination);
$archive->startBuffering();
foreach (['src', 'vendor'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        dirname(__DIR__) . '/' . $directory,
        FilesystemIterator::SKIP_DOTS,
    )) as $file) {
        if ($file->isFile()) {
            $archive->addFile($file->getPathname(), substr($file->getPathname(), strlen(dirname(__DIR__)) + 1));
        }
    }
}
$archive->setStub('#!' . $interpreter . "\n" . <<<'PHP'
<?php
Phar::mapPhar('phmake.phar');
require 'phar://phmake.phar/vendor/autoload.php';
new Tamiroh\Phmake\Console\Application()->run(array_slice($argv ?? [], 1));
__HALT_COMPILER();
PHP);
$archive->stopBuffering();
chmod($destination, 0755);
