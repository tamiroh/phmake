# AI Design Notes

This file is for AI agents and other automated contributors. It describes the current design intent of `phmake` so changes stay consistent with the codebase.

## Project Shape

- `src/Console` contains concrete CLI-facing implementations and the application entrypoint wiring.
- `src/Makefile` contains the core domain and execution rules.
- `src/Parser` parses a `Makefile` into an in-memory model.
- `tests/E2E` covers the real CLI entrypoint through `phmake`.
- `tests/Testing` contains shared test doubles and helpers.
- `tests/Unit` covers parser and domain behavior directly.

## Design Rules

- Keep the domain layer in `src/Makefile` independent from CLI and process-library details.
- `Makefile` and `Target` should describe execution flow, not terminal mechanics.
- Concrete infrastructure belongs in `src/Console`.
- Prefer passing execution dependencies at `run(...)` time rather than storing them inside the `Makefile` model.
- Avoid letting domain objects call `echo`, `fwrite`, `shell_exec`, `proc_open`, or Symfony Process directly.
- Do not edit `composer.json` or `composer.lock` by hand for dependency changes. Use Composer commands so both files stay in sync.

## Testing Policy

- Under `tests/Unit`, match the directory structure to the code under test.
- Use `tests/E2E` only for real CLI entrypoint coverage.
- Put shared test helpers and doubles in `tests/Testing`.
- After code changes, run `make check` and fix failures before finishing unless the environment prevents it.

## Change Heuristics

- If a change is about parsing syntax, start in `MakefileParser` and parser unit tests.
- If a change is about rebuild decisions or target execution order, start in `Target`/`Makefile` and their unit tests.
- If a change is about terminal output, subprocess behavior, or ANSI color, start in `src/Console`.
- If a change crosses layers, keep the domain-facing interfaces small and move complexity outward.
