# Terra + Luna workflow v3.4

## Responsibility split

The root orchestrator owns the feature as a whole. Luna is used through named custom roles whose TOML files pin the model and reasoning effort.

| Role | Model | Effort | Primary use |
| --- | --- | --- | --- |
| `code_explorer` | GPT-5.6 Luna | `xhigh` | targeted reconnaissance / required audits |
| `domain_worker` | GPT-5.6 Luna | `xhigh` | normal bounded domain implementation |
| `domain_deep_worker` | GPT-5.6 Luna | `xhigh` | difficult bounded domain implementation |
| `laravel_worker` | GPT-5.6 Luna | `xhigh` | normal bounded Laravel implementation |
| `laravel_deep_worker` | GPT-5.6 Luna | `xhigh` | difficult bounded Laravel implementation |
| `angular_worker` | GPT-5.6 Luna | `xhigh` | normal bounded Angular implementation |
| `angular_deep_worker` | GPT-5.6 Luna | `xhigh` | difficult bounded Angular implementation |
| `test_runner` | GPT-5.6 Luna | `high` | long verification jobs / failure triage |
| `reviewer` | GPT-5.6 Luna | `xhigh` | bounded independent review |
| `deep_reviewer` | GPT-5.6 Luna | `max` | high-risk/final review |

## Routing invariant

Select managed roles with the exact `agent_type` and `fork_turns="none"` by default. Do not pass `model` or `reasoning_effort`; the role TOML owns them. Never use `fork_turns="all"` for managed roles because full-history inheritance can defeat custom role routing. A routing failure must not silently degrade to a generic parent-model child.

## ExecPlan contract

v3.4 adopts the repository-native ExecPlan standard instead of maintaining a competing Terra/Luna format.

Planning priority:

1. `.agent/PLANS.md` — read completely and follow to the letter when present;
2. `.agent/plans/execplan-template-v2.md` — preferred skeleton;
3. active plans under `.agent/plans/`.

`$terra-feature-plan` contains bundled copies only as a fallback for repositories that do not yet have these files. Project-local planning rules remain authoritative.

ExecPlans should be self-contained, novice-guiding, outcome-focused living documents. They must preserve the required sections, exact repository context, observable acceptance, commands, recovery/rollback, living decision/discovery logs, and a Plan Change Note.

## Skills

Use:

1. `$terra-feature-plan` to create/update a PLANS.md-compliant ExecPlan.
2. `$terra-feature-implement` to execute the plan using adaptive mode, bounded batches, staged verification, and review.
3. `$terra-feature-status` for read-only progress checks.
4. `$terra-feature-harden` for final review and regression checking.
5. `$terra-routing-doctor` after Codex upgrades or whenever child routing looks suspicious.

## Execution modes

Before spawning children classify implementation work:

- **SPEC-DRIVEN**: an ExecPlan plus companion/runbook, or an exceptionally detailed self-contained ExecPlan, freezes contracts and next steps. Skip broad reconnaissance unless the plan itself requires a bounded audit or a concrete code fact is unknown.
- **PLAN-DRIVEN**: a detailed ExecPlan exists but some repository interpretation remains. Use targeted reconnaissance only for named gaps.
- **DISCOVERY-DRIVEN**: no implementation-grade plan exists or repository reality invalidates it. Reconnaissance and plan repair come first.

A required Slice 0 inventory/audit remains real SPEC-DRIVEN milestone work; SPEC-DRIVEN does not mean “never use explorer”.

## Role-selection precedence

The current managed Skill/AGENTS policy owns normal-vs-deep selection at execution time. Older ExecPlan role suggestions are advisory unless they state a still-valid technical reason for escalation.

Use deep only when the bounded batch still has unresolved hard reasoning after specs/plans are read: difficult idempotency/atomicity, contradictory invariants, persistent-state/data-integrity risk, subtle cross-subsystem transitions, difficult async/reactive behavior, or a large non-obvious edge-case surface.

## Batch budget

Avoid making one worker own several dependent slices just because they share a subsystem.

For SPEC-DRIVEN work:

- default to one slice or at most two tightly coupled adjacent slices per batch;
- preserve independent verification/contract boundaries;
- split before new persistent/public contracts, migrations, due-consumer/executor boundaries, cross-subsystem integration seams, or large independent test surfaces;
- deep worker means deeper reasoning inside a bounded batch, not the entire milestone.

This reduces oversized diffs, broad reviews, and repair rounds that re-read too much context.

## Wait discipline

Repeated `wait_agent` polling can waste expensive root turns even when the child is healthy.

After spawning a managed child:

- prefer one long wait for the phase;
- treat “not completed yet” as no new evidence;
- do not repeatedly emit root status/reasoning cycles while simply waiting;
- interact again only when runtime/tool limits require it, the child reports a decision/finding, the user asks for status, or there is evidence of a real stall;
- if repeated waits are technically unavoidable, keep parent reasoning minimal and do not reread project context between waits.

## Verification ownership

Separate local implementation proof from independent acceptance evidence.

**Implementation worker**
- run focused specs/tests directly tied to the batch;
- run narrow type/lint/guard checks only when needed;
- do not run broad/full project gates merely for reassurance when `test_runner` follows.

**Root**
- run short low-output checks such as status, `git diff --check`, or a tiny focused test;
- keep noisy output in temporary logs and surface only concise evidence.

**test_runner**
- run long subsystem/full suites, builds, headless/catalog gates, multi-command acceptance sets, and failure triage;
- receive exact commands;
- avoid repeating a broad check already performed by the worker unless independence is explicitly required or subsequent code changes made that evidence stale.

After a real review FAIL and repair, a second targeted verification is legitimate because the code changed. Repeating unaffected broad gates is not.

## Review policy

Review only a stable implementation batch.

- `reviewer`: batch-level correctness/contract/test-gap review.
- `deep_reviewer`: genuinely high-risk or final feature hardening.
- On P1/P2 correctness findings, block dependent slices, send a minimal repair packet, run focused repair tests, then targeted independent verification and re-review.
- If code changes after review begins, the old review is not final acceptance evidence for the changed batch.

## Legacy orchestration conflicts

Some repositories may contain older prose outside the managed block that hard-codes an unconditional explorer -> worker -> test_runner -> reviewer sequence or obsolete model/effort values.

When the Terra/Luna Skill is invoked, the current managed block governs agent orchestration. Older domain/architecture/testing/source-of-truth rules still apply. The validation script warns about known legacy orchestration patterns so they can be cleaned up deliberately rather than silently fighting the managed policy.

## Global vs project files

User-level runtime configuration belongs in each client's own Codex home:

- WSL CLI: `~/.codex/config.toml` and `~/.codex/agents/*.toml`
- Windows Desktop: `%USERPROFILE%\.codex\config.toml` and `%USERPROFILE%\.codex\agents\*.toml`

Reusable Skills are installed to each user's `.agents/skills` root. Project repositories receive project policy/docs/scripts and, when missing, the PLANS.md planning scaffold. Existing project-owned `.agent/PLANS.md` and `.agent/plans/execplan-template-v2.md` are never overwritten by the installer.

## Model catalog

Keep `model_catalog_json` while the installed Codex build still needs the Luna V2 catalog workaround. Treat the catalog as client/version-specific and refresh it after Codex upgrades rather than shipping a permanently frozen catalog in this kit.

## Read-only caveat

Current bounded custom-role overrides apply model, reasoning, developer instructions and a limited set of other fields, but role-local sandbox changes are not a reliable enforcement boundary. Explorer/reviewer roles are therefore instructed not to edit, and the root verifies the working tree after their completion.
