# Codex project instructions

For complex coding tasks, use the `astra-orchestrator` skill when its trigger conditions match.

The root agent owns architecture, decomposition, integration, and final verification.
Prefer specialized subagents for bounded exploration, implementation, testing, review, and technical research.

Do not delegate trivial work merely for parallelism.
Do not let multiple implementation agents edit the same files without explicit ownership boundaries.
User instructions always take precedence over this orchestration policy.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- livewire/flux (FLUXUI_FREE) - v2
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `fluxui-development` — Use this skill for Flux UI development in Livewire applications only. Trigger when working with <flux:*> components, building or customizing Livewire component UIs, creating forms, modals, tables, or other interactive elements. Covers: flux: components (buttons, inputs, modals, forms, tables, date-pickers, kanban, badges, tooltips, etc.), component composition, Tailwind CSS styling, Heroicons/Lucide icon integration, validation patterns, responsive design, and theming. Do not use for non-Livewire frameworks or non-component styling.
- `livewire-development` — Use for any task or question involving Livewire. Activate if user mentions Livewire, wire: directives, or Livewire-specific concepts like wire:model, wire:click, wire:sort, or islands, invoke this skill. Covers building new components, debugging reactivity issues, real-time form validation, drag-and-drop, loading states, migrating from Livewire 3 to 4, converting component formats (SFC/MFC/class-based), and performance optimization. Do not use for non-Livewire reactive UI (React, Vue, Alpine-only, Inertia.js) or standard Laravel forms without Livewire.
- `pest-testing` — Use this skill for Pest PHP testing in Laravel projects only. Trigger whenever any test is being written, edited, fixed, or refactored — including fixing tests that broke after a code change, adding assertions, converting PHPUnit to Pest, adding datasets, and TDD workflows. Always activate when the user asks how to write something in Pest, mentions test files or directories (tests/Feature, tests/Unit, tests/Browser), or needs browser testing, smoke testing multiple pages for JS errors, or architecture tests. Covers: it()/expect() syntax, datasets, mocking, browser testing (visit/click/fill), smoke testing, arch(), Livewire component tests, RefreshDatabase, and all Pest 4 features. Do not use for factories, seeders, migrations, controllers, models, or non-test PHP code.
- `tailwindcss-development` — Always invoke when the user's message includes 'tailwind' in any form. Also invoke for: building responsive grid layouts (multi-column card grids, product grids), flex/grid page structures (dashboards with sidebars, fixed topbars, mobile-toggle navs), styling UI components (cards, tables, navbars, pricing sections, forms, inputs, badges), adding dark mode variants, fixing spacing or typography, and Tailwind v3/v4 work. The core use case: writing or fixing Tailwind utility classes in HTML templates (Blade, JSX, Vue). Skip for backend PHP logic, database queries, API routes, JavaScript with no HTML/CSS component, CSS file audits, build tool configuration, and vanilla CSS.
- `fortify-development` — Laravel Fortify headless authentication backend development. Activate when implementing authentication features including login, registration, password reset, email verification, two-factor authentication (2FA/TOTP), profile updates, headless auth, authentication scaffolding, or auth guards in Laravel applications.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan Commands

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`, `php artisan tinker --execute "..."`).
- Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Debugging

- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.
- To execute PHP code for debugging, run `php artisan tinker --execute "your code here"` directly.
- To read configuration values, read the config files directly or run `php artisan config:show [key]`.
- To inspect routes, run `php artisan route:list` directly.
- To check environment variables, read the `.env` file directly.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== laravel/fortify rules ===

# Laravel Fortify

- Fortify is a headless authentication backend that provides authentication routes and controllers for Laravel applications.
- IMPORTANT: Always use the `search-docs` tool for detailed Laravel Fortify patterns and documentation.
- IMPORTANT: Activate `developing-with-fortify` skill when working with Fortify authentication features.

</laravel-boost-guidelines>

## Agent skills

### Issue tracker

Issues and PRDs are tracked in GitHub Issues. See `docs/agents/issue-tracker.md`.

### Triage labels

The repository uses the standard five-role triage label vocabulary. See `docs/agents/triage-labels.md`.

### Domain docs

Domain documentation uses a single-context layout. See `docs/agents/domain.md`.


<!-- terra-luna-orchestration:start v4 -->
## Terra + Luna feature orchestration

Large features use one root orchestrator and bounded role-specific Luna subagents.

### Root ownership

- Prefer GPT-5.6 Terra for the root orchestrator.
- The root owns architecture, ExecPlan quality, dependency ordering, shared/public contracts, cross-cutting decisions, integration, and final acceptance.
- Subagents execute bounded work. They do not own feature architecture or final acceptance.

### Planning contract

- When creating or revising an ExecPlan, read `.agent/PLANS.md` completely when it exists and follow it to the letter.
- Prefer `.agent/plans/execplan-template-v2.md` as the project skeleton.
- Store active plans under `.agent/plans/` unless the repository planning contract explicitly says otherwise.
- Keep ExecPlans self-contained, novice-guiding, outcome-focused, and resumable from the plan file alone.

### Managed Luna roles

- `code_explorer`: targeted repository reconnaissance, Luna `xhigh`, behaviorally read-only.
- `domain_worker`: bounded domain implementation, Luna `xhigh`.
- `domain_deep_worker`: difficult bounded domain implementation, Luna `xhigh`.
- `laravel_worker`: bounded Laravel implementation, Luna `xhigh`.
- `laravel_deep_worker`: difficult bounded Laravel implementation, Luna `xhigh`.
- `angular_worker`: bounded Angular implementation, Luna `xhigh`.
- `angular_deep_worker`: difficult bounded Angular implementation, Luna `xhigh`.
- `test_runner`: long-running verification jobs and failure triage, Luna `high`.
- `reviewer`: normal independent review, Luna `xhigh`, behaviorally read-only.
- `deep_reviewer`: high-risk/release-critical independent review, Luna `max`, behaviorally read-only.

### Mandatory spawn policy

For every managed role:

- pass the exact `agent_type`;
- use `fork_turns="none"` by default;
- never use `fork_turns="all"` with a managed role;
- do not pass `model` or `reasoning_effort`; the selected role config owns those values;
- do not silently fall back to a generic child or the parent model when routing fails;
- make the delegated message self-contained because a fresh child does not receive full parent history;
- instruct managed children not to spawn their own subagents.

A positive partial fork may be used only when deliberately required and known to preserve custom-role overrides.

### Current policy vs legacy orchestration text

When this managed workflow/Skill is invoked, this managed block is the active agent-orchestration policy. Older project text that hard-codes an unconditional `code_explorer -> worker -> test_runner -> reviewer` chain, old model/effort values, or ritual broad reconnaissance is legacy orchestration guidance if it conflicts with this block.

Preserve domain, architecture, testing, source-of-truth, and repository rules from older sections. Report orchestration conflicts rather than silently trying to obey incompatible policies. Do not delete or rewrite arbitrary legacy text without explicit user instruction.

Role suggestions in older ExecPlans are advisory. Re-evaluate normal vs deep at execution time under the current policy unless the plan records a still-valid technical reason for escalation.

### Normal vs deep role selection

Use the normal worker for bounded work whose design and procedure are already fixed. Do not escalate merely because the feature/domain is important, large, or persistent.

Escalate only when the delegated batch still contains unresolved non-trivial reasoning after authoritative plans/specs are read, such as contradictory invariants, difficult idempotency/atomicity, data-integrity/migration risk, subtle cross-subsystem state transitions, difficult async/reactive behavior, or a genuinely non-obvious edge-case surface.

A detailed implementation companion/runbook is evidence in favor of the normal worker, but it does not forbid deep escalation when the specific batch still contains one of the difficult conditions above.

### Feature Skills

Prefer the reusable Skills when available:

- `$terra-feature-plan`
- `$terra-feature-implement`
- `$terra-feature-status`
- `$terra-feature-harden`
- `$terra-routing-doctor`

### Execution modes

1. Detect execution mode before reconnaissance: SPEC-DRIVEN, PLAN-DRIVEN, or DISCOVERY-DRIVEN.
2. In SPEC-DRIVEN work, skip broad reconnaissance unless the ExecPlan itself requires a bounded audit or a concrete code fact is unknown.
3. Treat a plan-required Slice 0 audit as real milestone work, not as ritual rediscovery.
4. In PLAN-DRIVEN work, use targeted reconnaissance only for named gaps.
5. In DISCOVERY-DRIVEN work, reconcile repository facts and repair/create the ExecPlan before broad implementation.

### Batch budget

- Preserve dependency/verification boundaries already present in the ExecPlan.
- For SPEC-DRIVEN work, default to one slice or at most two tightly coupled adjacent slices per implementation batch.
- Do not collapse several dependent slices into one worker merely because they share a subsystem.
- Split before new persistent/public contracts, migrations, due-consumer/executor boundaries, cross-subsystem integration seams, or large independent test surfaces.
- A deep worker still receives a bounded batch; deep does not mean “implement the entire milestone”.

### Wait discipline

After spawning a child:

- prefer one long wait for the current phase;
- do not repeatedly call `wait_agent`/interaction only to learn that the child is still running;
- a no-result wait is not new evidence and should not trigger a new root reasoning/status cycle;
- retry only when runtime/tool limits require it, a concrete decision/finding needs input, the child appears genuinely stalled, or the user asks for status;
- do not emit repetitive progress messages while a healthy child is simply working.

### Verification ownership

- Implementation workers run focused tests/checks that directly prove their assigned batch.
- Do not make the worker run a broad/full suite merely for reassurance when an independent `test_runner` checkpoint is planned.
- Root runs predictably short, low-output checks with compact output.
- `test_runner` owns long subsystem/full suites, builds, headless/catalog/acceptance gates, and difficult failure triage.
- Avoid running the same broad gate in both worker and `test_runner` unless independence is explicitly required or later code changes made the first evidence stale.
- Do not repeat a fresh accepted pre-implementation baseline without a concrete reason.

### Review policy

- Start independent review only after the implementation batch is stable.
- Scope normal `reviewer` to the batch, affected acceptance criteria, relevant contracts, and related tests.
- Reserve `deep_reviewer` for genuinely high-risk/final hardening, not because an older plan mentions it by default.
- On real review FAIL, block dependent slices, issue a minimal repair packet, run focused repair tests, then perform targeted independent verification and re-review.
- A second verification/review pass after changed code is valid evidence; repeating unaffected broad gates is not.

### Delegation contract

Every implementation task must state the objective, ExecPlan section, allowed scope, fixed contracts, satisfied dependencies, relevant files/symbols, focused verification, definition of done, and instruction to report out-of-scope dependencies instead of expanding the task.

Repair packets should contain only the concrete findings, affected contracts/files, required regressions, and acceptance condition rather than forcing a full milestone rediscovery.

### Read-only roles

Current Codex custom-role bounded overrides do not reliably apply a role-local sandbox mode. `code_explorer`, `reviewer`, and `deep_reviewer` therefore use a behavioral read-only instruction. The root must verify that those agents did not edit files.
<!-- terra-luna-orchestration:end v4 -->
