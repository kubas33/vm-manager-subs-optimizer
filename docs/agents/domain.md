# Domain Docs

This repository uses a single domain context.

## Before exploring

- Read `CONTEXT.md` at the repository root when it exists.
- Read relevant decisions from `docs/adr/` when that directory exists.
- If either location does not exist, proceed silently. The `grill-with-docs` skill creates domain documentation lazily as terminology and decisions are resolved.

## Vocabulary

Use canonical terms from `CONTEXT.md` in code-facing explanations, tests, issues, and design proposals. Surface conflicts with existing ADRs instead of silently overriding them.
