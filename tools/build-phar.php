<?php

declare(strict_types=1);

$destination = $argv[1] ?? throw new InvalidArgumentException('Usage: build-phar.php OUTPUT.phar [PHP_INTERPRETER|env] [VERSION]');
$interpreter = $argv[2] ?? 'env';
if ($interpreter !== 'env' && (!str_starts_with($interpreter, '/') || !is_executable($interpreter) || preg_match('/\s/', $interpreter) === 1)) {
    throw new InvalidArgumentException('The PHP interpreter must be an executable absolute path without whitespace');
}
$version = $argv[3] ?? 'development';
if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+-]*\z/', $version) !== 1) {
    throw new InvalidArgumentException('The version must contain only letters, numbers, dots, plus signs, and hyphens');
}
if (file_exists($destination)) {
    throw new InvalidArgumentException('The output file already exists');
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
$archive->addFromString('src/Console/Input/Usage.php', str_replace(
    "private const string VERSION = 'development';",
    'private const string VERSION = ' . var_export($version, true) . ';',
    file_get_contents(dirname(__DIR__) . '/src/Console/Input/Usage.php'),
));
$archive->addFile(dirname(__DIR__) . '/LICENSE', 'LICENSE');
$archive->setSignatureAlgorithm(Phar::SHA256);
$archive->setStub('#!' . ($interpreter === 'env' ? '/usr/bin/env php' : $interpreter) . "\n" . <<<'PHP'
<?php
Phar::mapPhar('phmake.phar');
require 'phar://phmake.phar/vendor/autoload.php';
new Tamiroh\Phmake\Console\Application()->run(array_slice($argv ?? [], 1));
__HALT_COMPILER();
PHP);
$archive->stopBuffering();
chmod($destination, 0755);
