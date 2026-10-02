# Makefile engine

This directory implements make semantics: variable evaluation, rule selection,
dependency updates, and recipe execution. Text parsing lives in
[`src/Parser`](../Parser/); operating-system adapters and the CLI live in
[`src/Console`](../Console/).

Start with [`Makefile.php`](Makefile.php), which holds the parsed definitions,
then [`Execution/Build.php`](Execution/Build.php), which coordinates a build.
`Makefile` has no execution methods or evaluation context. The parser returns
`Parser/ParsedMakefile`, pairing definitions with the live `EvaluationContext`;
the caller passes both explicitly to `Build`.
[`RuleDefinitions.php`](RuleDefinitions.php) merges the rules read by the
parser: special targets, suffix rules, recipe overrides, and the default goal.
`Builtins.php` supplies default makefile names, variables, and rules, and the
variables describing an invocation. `MakefileErrorException.php`
is the common semantic error type.

## Definitions and operations

Keep this directory organized around GNU make concepts: rules, variables,
expansion, directory and implicit-rule search, target updates, and recipes.
The folders indicate where to look; they are not independent architectural
layers. A concept can keep its definitions and behavior together.

`Makefile` brings together the definitions read from makefiles:

| Makefile member | Meaning | Where to look |
| --- | --- | --- |
| `targets`, `targetsByName`, `patterns` | Targets and their explicit or pattern rules | `Rule/Target`, `Rule/BuildRule`, `Rule/PatternRule` |
| `defaultGoal` | The goal used when none is requested | `Makefile`, `RuleDefinitions` |
| `variables` | Global variable definitions at the end of reading | `Variable/Variable` |
| `targetVariables` | Target-specific and pattern-specific variable definitions | `Variable/TargetVariables` |
| `exports` | Export/unexport directives | `Variable/Environment/Exports` |
| `searchPaths` | Selective directory search definitions | `Search/SearchPaths` |

A `Rule/BuildRule` describes prerequisites and a recipe. `Rule/Recipe` and
`Rule/Command` retain recipe definitions.
`Execution/Build::update()` decides which targets to update, and
`Execution/Recipe/RecipeRunner::run()` runs their recipes.

For example, `TargetVariables` keeps both target-specific definitions and the
rules for applying them to a variable scope. `Exports` records directives and
computes the exported environment. `SearchPaths` records `vpath` directives and
performs directory search. These methods belong with the make concept they
implement.

## Where make's operations happen

| Directory | Question it answers | Main classes |
| --- | --- | --- |
| `Rule/` | What are a target, its prerequisites, and its recipe? | `Target`, `BuildRule`, `PatternRule`, `Prerequisites`, `Recipe`, `Command` |
| `Variable/` | What does an assignment define, and which variable applies here? | `Variable`, `Assignment`, `TargetVariables`, `VariableScope`, `AutomaticVariables` |
| `Expansion/` | How are variable references, functions, and deferred expressions expanded? | `VariableExpander`, `CommandExpander`, `ExpandedCommand`, `Functions`, `EvaluationContext`, `MakefileReading`, `SecondaryExpansion` |
| `Variable/Environment/` | Which variables reach a shell command? | `Exports`, `EnvironmentState`, `ExportingShell` |
| `Expansion/LoadedObject/` | How do load directives and registered functions participate in expansion? | `LoadedObjects`, `LoadedObject`, `LoadedFunction`, `ExpansionApi` |
| `Search/` | Which rule and file path can satisfy this target? | `RuleSearch`, `ImplicitCandidate`, `SearchPaths`, `SearchState` |
| `Execution/` | Which dependencies need updating, and what is the overall result? | `Build`, `BuildState`, `PrerequisiteChain`, `UpdateResult`, `MakefileRemake`, `ExecutionOptions` |
| `Execution/Files/` | Is a file out of date, and should it survive the build? | `BuildFiles`, `IntermediateFiles`, `FileOptions` |
| `Execution/Recipe/` | How is a recipe expanded and executed? | `RecipeRunner`, `CommandRunner`, `CommandResult` |
| `Execution/Scheduling/` | Which target updates may proceed or wait? | `Jobs`, `TargetUpdate`, `DependencyOrder`, `ParallelOptions` |
| `Invocation/` | Which options change reading, and how are they passed to sub-makes through `MAKEFLAGS`? | `InvocationOptions`, `MakeFlags`, `CommandVariables`, `OptionOverrides` |
| `Reporting/` | How are failures, debug events, and rebuild reasons explained? | `Diagnostics`, `DebugTrace`, `RecipeTrace`, `ReportingOptions` |
| `IO/` | What are the contracts at the boundary with the host? | `Filesystem`, `SourceFiles`, `Shell`, `Output`, `RecipeOutput`, `JobSlots`, `TargetUpdates`, `IntermediateDeletionOrder`, `LoadableObjects`, `LoadedObjectApi`, `Guile` |

## Following a build

The diagram shows the main runtime collaborations, not a strict dependency
hierarchy. Expansion happens both while reading makefiles and while updating
targets.

```mermaid
flowchart TD
    Parser[Parser/MakefileParser] --> Definitions[Makefile + Rule definitions]
    Build[Execution/Build] --> Definitions
    Parser --> Expansion[Expansion/VariableExpander]
    Build --> Search[Search/RuleSearch]
    Build --> Files[Execution/Files/BuildFiles]
    Build --> Scheduling[Execution/Scheduling/Jobs]
    Build --> Recipe[Execution/Recipe/RecipeRunner]
    Search --> Expansion
    Build --> Expansion
    Recipe --> Expansion
    Recipe --> Environment[Variable/Environment/ExportingShell]
    Environment --> Shell[IO/Shell]
    Files --> Filesystem[IO/Filesystem]
    Build --> Reporting[Reporting]
    Reporting --> Output[IO/Output]
```

1. `Parser/MakefileParser` reads definitions using `Variable` and `Expansion`.
   It passes each `Rule/RuleDefinition` to `RuleDefinitions`, whose `makefile()`
   method collects the combined definitions into a `Makefile`.
   `Parser/ParsedMakefile` returns that definition and its evaluation context
   separately. This is not a fully expanded build plan: recursive variables and
   secondary prerequisite expressions can remain deferred.
2. `Build::run()` starts from the requested goals. Before that, `MakefileRemake`
   uses `Build::remake()` to update the `ReadFile` inputs and decides whether make
   must restart and read them again.
3. `Build::update()` walks dependencies. `RuleSearch::resolve()` selects explicit
   or implicit rules and consults search paths. `SecondaryExpansion` and
   `ExtraPrerequisites` evaluate prerequisites in their applicable contexts.
4. `BuildFiles` supplies timestamps and rebuild decisions. `BuildState` records
   shared results; each `PrerequisiteChain` carries a branch's ancestors and inherited
   target-specific variables. `Jobs` decides which target updates may proceed or wait,
   using `IO/TargetUpdates` for execution and `IO/JobSlots` for job slots.
5. `RecipeRunner::run()` sets automatic variables and applies recipe options.
   `Rule/Command` retains the original expression. `Expansion/CommandExpander`
   produces an `Expansion/ExpandedCommand` containing text and original prefix
   metadata; `Execution/Recipe/CommandRunner` interprets prefixes and executes
   the expanded lines. `ExportingShell` supplies the environment before
   delegating to `IO/Shell`.
6. `Reporting` explains failures and rebuild reasons. `BuildFiles::cleanup()`
   removes intermediate files according to `IntermediateFiles`.

## State lifetimes

- `Makefile` holds definitions, including a snapshot of global variables at the
  end of reading. Build-time variable lookup uses the explicitly supplied
  `EvaluationContext`, not that snapshot.
- `EvaluationContext` belongs to one read and its subsequent build. The same
  context continues through makefile remaking and ordinary goals, preserving
  `$(eval ...)` mutations, shell status, exports, and loaded objects. It is not
  cloned at the read/build boundary. Restarting creates a new parser and context.
- `Build` owns `BuildState`, rule-search state, file state, and scheduling state.
  These are created for each build, independently of stored definitions.
- Definition holders such as `Exports` and `TargetVariables` are still mutable;
  separating the context does not make the complete definition graph deeply
  immutable. Export directives are shared with evaluation deliberately.


`EvaluationContext` is shared expansion state, not a host-service factory. Its
members have these make-specific purposes:

| State | Purpose and lifetime |
| --- | --- |
| `variables` | Live global definitions, including eval changes and `.SHELLSTATUS`, shared from reading through ordinary goals. |
| `reading` | `MakefileReading` retains `.POSIX`, the initial-reading flag and the callback that reads eval results. Ending initial reading forbids new prerequisites while allowing later variable changes. |
| `exports`, `environment` | Export directives, inherited environment and protection against recursive shell expansion while exporting variables. |
| `loadedObjects` | Load directives and registered functions, retained during remaking and reloaded when necessary. |
| `shell`, `filesystem`, `guile` | Capabilities used by expansion; supplied by the caller, with host resources owned outside this directory. |
| `reporting` | Options controlling warnings and traces during expansion. |

A restart replaces this entire state. Merely moving from reading to updating
must not clone it or discard its mutations.

## Boundaries that are easy to confuse

- `Rule/Recipe` is the shared recipe definition attached to one or more targets;
  `Execution/Recipe` contains the code that evaluates and runs its commands.
- `Search` determines how a target can be built. `Execution` decides whether to
  build it and coordinates its dependencies. Implicit search can itself inspect
  prerequisite rules, so it is not just a filesystem lookup.
- `Execution/Files` implements make's timestamp and intermediate-file policies.
  Actual filesystem access goes through `IO/Filesystem` to `Console/Filesystem`.
- `Variable/Environment/ExportingShell` handles exported variables for both
  `$(shell ...)` and recipes. Process launching lives in `Console/Process`.
- `Invocation` owns the meaning of options such as `-e`, `-r`, and `-R`, and how
  `MAKEFLAGS` is built from them. Reading command-line arguments and `MAKEFLAGS`
  text into those options stays in `Console/Input/CommandLine`.
- `Expansion/LoadedObject` implements the `load` directive and registered functions.
  `IO/LoadableObjects` loads an object and calls its functions. `IO/LoadedObjectApi`
  is the Loaded Object API (`gmk_add_function`, `gmk_expand`, `gmk_eval`); unlike
  the other `IO` contracts, the engine implements it and the host calls it back.
  `Console/Process/ModuleObjects` owns one native host per object and closes it before
  a reload. `LoadedObject` retains only the path, setup function, source and load state;
  it never stores a host process or a factory.
- The `guile` function uses `IO/Guile` directly, with make expansion and evaluation
  callbacks. `Console/Process/ModuleGuile` keeps its Scheme interpreter alive across
  calls, independently of loaded objects. Native request encoding remains in
  `Console/Process/ModuleHost` for both operations.
- `Jobs` owns shared-prerequisite waiting, circular dependency handling, job-slot
  decisions and draining unfinished updates. `IO/TargetUpdates` provides execution
  without exposing fibers. `Console/Process/FiberUpdates` owns their creation and
  resumption; `Console/Process/Waiting` suspends them. `RecipeProcess` and
  `OutputBuffer` use those same fibers for process waiting and synchronized output.
- `IntermediateFiles` decides which files are intermediate and which are preserved.
  `IO/IntermediateDeletionOrder` supplies cleanup order. Its implementation,
  `Console/Filesystem/DeletionOrder`, reproduces GNU make's internal file-table hash
  order solely for observable compatibility, not as a public specification guarantee.
- Exceptions stay with the operation they describe. `Reporting` formats them;
  it does not own build failures or interruption control flow.

The enforced dependency boundary is outside this directory: `Makefile` does
not depend on `Parser`, `Console`, or concrete process libraries. There are no
separate dependency rules between `Rule`, `Variable`, `Expansion`, `Search`, and
`Execution`. `mago guard` also rejects native functions for output, processes,
files, the environment, the clock, sleeping, and randomness here and in `Parser`;
the existing interfaces in `IO/` connect these operations to the host.


## Host contracts in make terms

These contracts describe what make needs, rather than how an operating system
provides it. They may perform effects; “domain” here does not mean that shell
expansion or recipe execution is side-effect-free.

| Contract | GNU make use |
| --- | --- |
| `Filesystem` | Target timestamps and existence, directory search, `wildcard`, `realpath`, `file`, `touch`, and deletion of intermediate or failed targets. |
| `SourceFiles`, `SourceText` | Finding and reading default or included makefiles; retaining read failures and modification times for remaking and restart decisions. |
| `Shell`, `ShellResult` | `$(shell ...)`, `!=` assignments, recipe commands, exported variables, `SHELL`, `.SHELLFLAGS` and exit status. |
| `Output`, `RecipeOutput` | Info, warnings, diagnostics, recipe echoing, and target / line boundaries for `--output-sync`. |
| `JobSlots`, `TargetUpdates` | Parallel target updates, shared slots, and waiting while other prerequisites or recipes proceed. |
| `IntermediateDeletionOrder` | Compatibility with the observed intermediate-file cleanup order. |
| `LoadableObjects`, `LoadedObjectApi` | Object loading, setup results, registered functions and the Loaded Object Interface callbacks. |
| `Guile` | Scheme evaluation and its `gmk-expand` / `gmk-eval` callbacks. |

Do not split each file or output operation into its own interface just to rename
I/O. Keep related make operations together. Descriptors, process handles, fibers,
serialization and native-host construction belong to the implementations outside
this directory. PHP arrays, closures, exceptions and internal helper objects are
not architectural violations merely because they are implementation tools.

## Reading order

For the overall flow, read `Makefile` → `Build::run()` / `Build::update()` →
`RuleSearch::resolve()` → `BuildFiles::needsRebuild()` → `RecipeRunner::run()`.
Then follow the subsystem relevant to the behavior being changed:

- Variable behavior: `Variable/Variable` → `Variable/Assignment` →
  `Variable/TargetVariables` / `Variable/VariableScope` →
  `Expansion/VariableExpander` → `Expansion/Functions`.
- Parallel builds: `Jobs` → `TargetUpdate` / `IO/TargetUpdates`; follow the latter
  to `Console/Process/FiberUpdates` for execution details. When no target update can
  proceed, `Jobs` waits through `IO/JobSlots::waitForJobs()`.
- File lifetime: `BuildFiles` → `IntermediateFiles`; `IO/IntermediateDeletionOrder`
  preserves GNU make's observed order for deleting intermediate files.

Keep broad build coordination in `Execution/Build.php`, and put subsystem
helpers and options next to the behavior they control. Use GNU make concepts to
name and group code; introduce a folder when it makes one of those concepts
easier to follow.
