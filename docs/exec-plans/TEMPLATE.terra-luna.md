# ExecPlan: <feature name>

Status: Proposed | Active | Blocked | Done
Primary owner: root orchestrator (prefer GPT-5.6 Terra)
Source of truth: <ticket/spec/GDD/path>
Last updated: YYYY-MM-DD

## 1. Goal

Describe the user-visible or system-level outcome and explicit non-goals.

## 2. Acceptance criteria

- [ ] AC1: ...
- [ ] AC2: ...

## 3. Current-state analysis

Document relevant execution paths, modules, tests, data flow, and patterns to reuse.

## 4. Design decisions

| ID | Decision | Rationale | Impact |
| --- | --- | --- | --- |
| D1 | ... | ... | ... |

## 5. Contracts / data / API

Document model, persistence, event, DTO, route, UI, compatibility, or migration changes.

## 6. Dependency graph

```text
T01
 ├─> T02
 └─> T03
      └─> T04
```

## 7. Implementation tasks

### T01 - <task name>

- Owner: root | `domain_worker` | `domain_deep_worker` | `laravel_worker` | `laravel_deep_worker` | `angular_worker` | `angular_deep_worker`
- Depends on: none
- Parallel-safe with: <task IDs or none>
- Scope: <exact modules/files or responsibility boundary>
- Objective: ...
- Fixed contracts: ...
- Constraints: ...
- Definition of done:
  - [ ] ...
- Verification:
  - `command`
- Integration notes: ...

## 8. Temporary file ownership

| Task | Role | Files/modules owned while running |
| --- | --- | --- |
| T02 | `laravel_worker` | `...` |

No concurrent tasks may own the same file.

## 9. Testing strategy

### Focused verification
- ...

### Integration/regression verification
- ...

## 10. Risks and edge cases

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| ... | ... | ... | ... |

## 11. Migration and backward compatibility

Describe old-data behavior, rollout, rollback, compatibility, and migration concerns.

## 12. Integration checkpoints

- [ ] Root review after ...
- [ ] Root review before ...

## 13. Acceptance matrix

| Acceptance criterion | Implemented by task(s) | Verification |
| --- | --- | --- |
| AC1 | T01 | ... |

## 14. Progress / decision log

- YYYY-MM-DD: ...

## 15. Final verification

- [ ] All acceptance criteria have explicit evidence.
- [ ] Root reviewed the coherent feature diff.
- [ ] Relevant focused and integration checks pass.
- [ ] Error handling and important edge cases were reviewed.
- [ ] Compatibility/migrations were verified when relevant.
- [ ] `deep_reviewer` was used for high-risk/final slices when warranted.
- [ ] No unrelated changes remain.
- [ ] Follow-up work is explicit.
