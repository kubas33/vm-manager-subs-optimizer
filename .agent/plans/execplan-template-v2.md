# <JIRA-ID>: <Short action-oriented title>

This ExecPlan is a living document. The sections `Progress`, `Surprises & Discoveries`, `Decision Log`, and `Outcomes & Retrospective` must be kept up to date as work proceeds.

This plan must be maintained in accordance with `.agent/PLANS.md` at the repository root.

## Purpose / Big Picture

Describe user-visible value in 2-4 sentences. Explain what becomes possible after this change and how a human can verify it works.

## Scope Freeze

State exact in-scope and out-of-scope boundaries for this branch.

In scope:
- <item>
- <item>

Out of scope:
- <item>
- <item>

## API/UI Contract Snapshot

Document only the contract needed for this branch (endpoints, request/response keys, status values). Keep it explicit so BE and FE can proceed without guesswork.

- Endpoint: `<METHOD /path>`
  - Request: `<required fields>`
  - Response: `<required fields>`
  - Errors: `<codes and meanings>`

## Progress

- [ ] (YYYY-MM-DD HH:MMZ) Add first concrete implementation step.
- [ ] Add second concrete implementation step.
- [ ] Add tests and validation.

## Surprises & Discoveries

- Observation: ...
  Evidence: ...

## Decision Log

- Decision: ...
  Rationale: ...
  Date/Author: ...

## Outcomes & Retrospective

Summarize achieved behavior, known gaps, and lessons learned when milestone/plan is complete.

## Context and Orientation

List exact repo paths and explain current state for a newcomer.

- `path/to/file`: what it currently does
- `path/to/file`: why it matters

Define non-obvious terms used in the plan.

## Plan of Work

Describe sequence of edits in prose. For each change, name exact file path and expected behavior impact.

## Parallel Work Map

Explicitly mark what can run in parallel and what is blocked by dependency.

Parallel-safe tracks:
- BE Track A: <work>
- FE Track B: <work>

Blocking dependencies:
- FE Task X waits for API field `<field>` from endpoint `<endpoint>`.

## Concrete Steps

Provide exact commands and working directory.

    cd <repo-root>
    <command>
    <command>

## Validation and Acceptance

Define human-verifiable outcomes and exact tests.

- Run: `<test command>`
- Expect: `<result>`
- Manual flow:
  1. <step>
  2. <step>
  3. <observable result>

## Idempotence and Recovery

Explain safe re-run behavior and rollback/fallback strategy.

- Re-run safety: <statement>
- Failure recovery: <statement>

## Rollback / Fallback

Describe how to disable or bypass new behavior without destructive operations.

- Feature flag / conditional path: <details>
- Safe fallback path: <details>

## Artifacts and Notes

Include concise payload examples or expected logs.

    {
      "example": true
    }

## Interfaces and Dependencies

List required classes/interfaces/functions and where they live.

- `Namespace\\Class::method(...)` in `path/to/file`
- `Namespace\\Interface` in `path/to/file`

## PR Exit Checklist

- [ ] All in-scope acceptance criteria verified.
- [ ] Tests added/updated and passing.
- [ ] No regression in legacy/compatibility path.
- [ ] Docs/contracts updated for changed behavior.
- [ ] Plan sections updated to final state (`Progress`, `Decision Log`, `Outcomes & Retrospective`).

Plan Change Note: <YYYY-MM-DD> — <what changed in this plan and why>.
