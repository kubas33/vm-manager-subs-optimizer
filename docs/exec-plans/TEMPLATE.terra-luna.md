# Compatibility note

Terra + Luna v3.4 uses the repository-native ExecPlan contract:

- `.agent/PLANS.md`
- `.agent/plans/execplan-template-v2.md`
- active plans under `.agent/plans/`

This legacy `docs/exec-plans/TEMPLATE.terra-luna.md` path is retained only so upgrades do not leave an older competing Terra/Luna template looking authoritative. Do not create new ExecPlans from this file.
