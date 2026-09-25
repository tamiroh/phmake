<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

/** Candidate searches clone this state; only an accepted branch is committed. */
final class SearchState
{
    /** @var array<string, Target|null> */
    public array $targets = [];

    /** @var array<string, true> */
    public array $intermediates = [];

    /** @var array<string, true> */
    public array $terminal = [];

    /** @var array<string, true> */
    public array $mentioned = [];

    /** @var array<string, string> */
    public array $paths = [];

    /** @var array<string, bool> */
    public array $existed = [];
}
