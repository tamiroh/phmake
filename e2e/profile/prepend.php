<?php

declare(strict_types=1);

// Loaded through auto_prepend_file in profiling images. Each PHP process
// writes its own collapsed stacks, so recursive make invocations stay apart.
(static function (): void {
    $directory = getenv('PHMAKE_PROFILE');
    if ($directory === false || $directory === '' || !extension_loaded('excimer')) {
        return;
    }
    $profiler = new ExcimerProfiler();
    $profiler->setEventType(EXCIMER_REAL);
    $profiler->setPeriod(0.001);
    $profiler->start();
    register_shutdown_function(static function () use ($profiler, $directory): void {
        $profiler->stop();
        file_put_contents($directory . '/' . getmypid() . '.folded', $profiler->getLog()->formatCollapsed());
    });
})();
