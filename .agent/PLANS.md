# Codex Execution Plans (ExecPlans)

This document describes the requirements for an execution plan ("ExecPlan"), a design document that a coding agent can follow to deliver a working feature or system change. Treat the reader as a complete beginner to this repository: they have only the current working tree and the single ExecPlan file you provide. There is no memory of prior plans and no external context.

## How to use ExecPlans and PLANS.md

When authoring an executable specification (ExecPlan), follow PLANS.md _to the letter_. If it is not in your context, refresh your memory by reading the entire PLANS.md file. Be thorough in reading (and re-reading) source material to produce an accurate specification. When creating a spec, start from the skeleton and flesh it out as you do your research.

When you create an ExecPlan, save it under `.agent/plans/` with a descriptive filename so the plan is persisted and easy to find later.
Start from `.agent/plans/execplan-template-v2.md` unless there is a strong, documented reason to diverge.

When implementing an executable specification (ExecPlan), do not prompt the user for "next steps"; simply proceed to the next milestone. Keep all sections up to date, add or split entries in the list at every stopping point to affirmatively state the progress made and next steps. Resolve ambiguities autonomously, and commit frequently.

When discussing an executable specification (ExecPlan), record decisions in a log in the spec for posterity; it should be unambiguously clear why any change to the specification was made. ExecPlans are living documents, and it should always be possible to restart from _only_ the ExecPlan and no other work.

When researching a design with challenging requirements or significant unknowns, use milestones to implement proof of concepts, "toy implementations", etc., that allow validating whether the user's proposal is feasible. Read the source code of libraries by finding or acquiring them, research deeply, and include prototypes to guide a fuller implementation.

## Requirements

NON-NEGOTIABLE REQUIREMENTS:

* Every ExecPlan must be fully self-contained. Self-contained means that in its current form it contains all knowledge and instructions needed for a novice to succeed.
* Every ExecPlan is a living document. Contributors are required to revise it as progress is made, as discoveries occur, and as design decisions are finalized. Each revision must remain fully self-contained.
* Every ExecPlan must enable a complete novice to implement the feature end-to-end without prior knowledge of this repo.
* Every ExecPlan must produce a demonstrably working behavior, not merely code changes to "meet a definition".
* Every ExecPlan must define every term of art in plain language or do not use it.

Purpose and intent come first. Begin by explaining, in a few sentences, why the work matters from a user's perspective: what someone can do after this change that they could not do before, and how to see it working. Then guide the reader through the exact steps to achieve that outcome, including what to edit, what to run, and what they should observe.

The agent executing your plan can list files, read files, search, run the project, and run tests. It does not know any prior context and cannot infer what you meant from earlier milestones. Repeat any assumption you rely on. Do not point to external blogs or docs; if knowledge is required, embed it in the plan itself in your own words. If an ExecPlan builds upon a prior ExecPlan and that file is checked in, incorporate it by reference. If it is not, you must include all relevant context from that plan.

## Formatting

Format and envelope are simple and strict. Each ExecPlan must be one single fenced code block labeled as `md` that begins and ends with triple backticks. Do not nest additional triple-backtick code fences inside; when you need to show commands, transcripts, diffs, or code, present them as indented blocks within that single fence. Use indentation for clarity rather than code fences inside an ExecPlan to avoid prematurely closing the ExecPlan's code fence. Use two newlines after every heading, use # and ## and so on, and correct syntax for ordered and unordered lists.

When writing an ExecPlan to a Markdown (.md) file where the content of the file _is only_ the single ExecPlan, you should omit the triple backticks.

Write in plain prose for rationale and design context. Use structured lists where the template requires explicit structure (`Scope Freeze`, `API/UI Contract Snapshot`, `Parallel Work Map`, `PR Exit Checklist`, and `Progress`). Narrative sections must remain prose-first.

## Guidelines

Self-containment and plain language are paramount. If you introduce a phrase that is not ordinary English ("daemon", "middleware", "RPC gateway", "filter graph"), define it immediately and remind the reader how it manifests in this repository (for example, by naming the files or commands where it appears). Do not say "as defined previously" or "according to the architecture doc." Include the needed explanation here, even if you repeat yourself.

Avoid common failure modes. Do not rely on undefined jargon. Do not describe "the letter of a feature" so narrowly that the resulting code compiles but does nothing meaningful. Do not outsource key decisions to the reader. When ambiguity exists, resolve it in the plan itself and explain why you chose that path. Err on the side of over-explaining user-visible effects and under-specifying incidental implementation details.

Anchor the plan with observable outcomes. State what the user can do after implementation, the commands to run, and the outputs they should see. Acceptance should be phrased as behavior a human can verify ("after starting the server, navigating to [http://localhost:8080/health](http://localhost:8080/health) returns HTTP 200 with body OK") rather than internal attributes ("added a HealthCheck struct"). If a change is internal, explain how its impact can still be demonstrated (for example, by running tests that fail before and pass after, and by showing a scenario that uses the new behavior).

Specify repository context explicitly. Name files with full repository-relative paths, name functions and modules precisely, and describe where new files should be created. If touching multiple areas, include a short orientation paragraph that explains how those parts fit together so a novice can navigate confidently. When running commands, show the working directory and exact command line. When outcomes depend on environment, state the assumptions and provide alternatives when reasonable.

Be idempotent and safe. Write the steps so they can be run multiple times without causing damage or drift. If a step can fail halfway, include how to retry or adapt. If a migration or destructive operation is necessary, spell out backups or safe fallbacks. Prefer additive, testable changes that can be validated as you go.

Validation is not optional. Include instructions to run tests, to start the system if applicable, and to observe it doing something useful. Describe comprehensive testing for any new features or capabilities. Include expected outputs and error messages so a novice can tell success from failure. Where possible, show how to prove that the change is effective beyond compilation (for example, through a small end-to-end scenario, a CLI invocation, or an HTTP request/response transcript). State the exact test commands appropriate to the project’s toolchain and how to interpret their results.

Capture evidence. When your steps produce terminal output, short diffs, or logs, include them inside the single fenced block as indented examples. Keep them concise and focused on what proves success. If you need to include a patch, prefer file-scoped diffs or small excerpts that a reader can recreate by following your instructions rather than pasting large blobs.

## Milestones

Milestones are narrative, not bureaucracy. If you break the work into milestones, introduce each with a brief paragraph that describes the scope, what will exist at the end of the milestone that did not exist before, the commands to run, and the acceptance you expect to observe. Keep it readable as a story: goal, work, result, proof. Progress and milestones are distinct: milestones tell the story, progress tracks granular work. Both must exist. Never abbreviate a milestone merely for the sake of brevity, do not leave out details that could be crucial to a future implementation.

Each milestone must be independently verifiable and incrementally implement the overall goal of the execution plan.

## Living plans and design decisions

* ExecPlans are living documents. As you make key design decisions, update the plan to record both the decision and the thinking behind it. Record all decisions in the `Decision Log` section.
* ExecPlans must contain and maintain all sections from `execplan-template-v2.md`. At minimum this includes `Scope Freeze`, `API/UI Contract Snapshot`, `Progress`, `Surprises & Discoveries`, `Decision Log`, `Outcomes & Retrospective`, `Parallel Work Map`, `Rollback / Fallback`, `PR Exit Checklist`, and `Plan Change Note`.
* When you discover optimizer behavior, performance tradeoffs, unexpected bugs, or inverse/unapply semantics that shaped your approach, capture those observations in the `Surprises & Discoveries` section with short evidence snippets (test output is ideal).
* If you change course mid-implementation, document why in the `Decision Log` and reflect the implications in `Progress`. Plans are guides for the next contributor as much as checklists for you.
* At completion of a major task or the full plan, write an `Outcomes & Retrospective` entry summarizing what was achieved, what remains, and lessons learned.

# Prototyping milestones and parallel implementations

It is acceptable—-and often encouraged—-to include explicit prototyping milestones when they de-risk a larger change. Examples: adding a low-level operator to a dependency to validate feasibility, or exploring two composition orders while measuring optimizer effects. Keep prototypes additive and testable. Clearly label the scope as “prototyping”; describe how to run and observe results; and state the criteria for promoting or discarding the prototype.

Prefer additive code changes followed by subtractions that keep tests passing. Parallel implementations (e.g., keeping an adapter alongside an older path during migration) are fine when they reduce risk or enable tests to continue passing during a large migration. Describe how to validate both paths and how to retire one safely with tests. When working with multiple new libraries or feature areas, consider creating spikes that evaluate the feasibility of these features _independently_ of one another, proving that the external library performs as expected and implements the features we need in isolation.

## Skeleton of a Good ExecPlan (v2)

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

If you follow the guidance above, a single, stateless agent -- or a human novice -- can read your ExecPlan from top to bottom and produce a working, observable result. That is the bar: SELF-CONTAINED, SELF-SUFFICIENT, NOVICE-GUIDING, OUTCOME-FOCUSED.

When you revise a plan, you must ensure your changes are comprehensively reflected across all sections, including the living document sections, and you must write a note at the bottom of the plan describing the change and the reason why. ExecPlans must describe not just the what but the why for almost everything.
