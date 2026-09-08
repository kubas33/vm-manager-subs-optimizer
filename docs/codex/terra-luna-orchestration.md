# Terra + Luna workflow v3

## Responsibility split

The root orchestrator owns the feature as a whole. Luna is used through named custom roles whose TOML files pin the model and reasoning effort.

| Role | Model | Effort | Primary use |
| --- | --- | --- | --- |
| `code_explorer` | GPT-5.6 Luna | `xhigh` | reconnaissance |
| `domain_worker` | GPT-5.6 Luna | `xhigh` | normal domain implementation |
| `domain_deep_worker` | GPT-5.6 Luna | `max` | difficult bounded domain implementation |
| `laravel_worker` | GPT-5.6 Luna | `xhigh` | normal Laravel implementation |
| `laravel_deep_worker` | GPT-5.6 Luna | `max` | difficult bounded Laravel implementation |
| `angular_worker` | GPT-5.6 Luna | `xhigh` | normal Angular implementation |
| `angular_deep_worker` | GPT-5.6 Luna | `max` | difficult bounded Angular implementation |
| `test_runner` | GPT-5.6 Luna | `high` | tests/build/lint/typecheck |
| `reviewer` | GPT-5.6 Luna | `xhigh` | normal independent review |
| `deep_reviewer` | GPT-5.6 Luna | `max` | high-risk/final review |

## Routing invariant

Selecting a managed role is the routing mechanism. The root should call `spawn_agent` with the exact `agent_type` and `fork_turns="none"` by default. The root should not pass `model` or `reasoning_effort` for managed roles.

Full-history forks are intentionally prohibited for managed roles because they inherit the parent agent type/model/reasoning and can defeat the role override. A role-routing failure must not silently degrade to a generic parent-model child.

## Skills

Use:

1. `$terra-feature-plan` to inspect the repository and create the ExecPlan.
2. `$terra-feature-implement` to execute the plan using normal/deep worker selection.
3. `$terra-feature-status` for read-only progress checks.
4. `$terra-feature-harden` for final review and regression checking.
5. `$terra-routing-doctor` after Codex upgrades or whenever child routing looks suspicious.

## Global vs project files

User-level runtime configuration belongs in each client's own Codex home:

- WSL CLI: `~/.codex/config.toml` and `~/.codex/agents/*.toml`
- Windows Desktop: `%USERPROFILE%\.codex\config.toml` and `%USERPROFILE%\.codex\agents\*.toml`

Reusable Skills are installed to each user's `.agents/skills` root. Project repositories receive only project policy/docs/scripts. The kit deliberately does not place role model/effort routing in project-local config because Windows Desktop and CLI can load different config layers/builds.

## Model catalog

Keep `model_catalog_json` while the installed Codex build still needs the Luna V2 catalog workaround. Treat the catalog as client/version-specific: it replaces the bundled catalog for that client at startup, so refresh it after Codex upgrades rather than shipping a permanently frozen catalog in this kit.

## Read-only caveat

Current bounded custom-role overrides apply model, reasoning, developer instructions and a limited set of other fields, but role-local sandbox changes are not a reliable enforcement boundary. Explorer/reviewer roles are therefore instructed not to edit, and the root verifies the working tree after their completion.
