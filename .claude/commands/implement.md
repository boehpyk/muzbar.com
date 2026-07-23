---
description: Build an approved feature in DDD order (Implement phase)
argument-hint: <feature-name>
---

Implement the **approved** plan for `$1` by working `docs/specs/$1/task-list.md` in order.

Only proceed if the plan has been approved by the user. Work tasks in DDD canonical order, delegating
to the right specialist and committing per task on the `feature/$1` branch:

1. **Domain + Application tasks** → `domain-modeler` agent (aggregates, value objects, events, ports,
   command/query handlers; pure PHP, no framework in Domain).
2. **Infrastructure tasks** → `symfony-dev` agent (Doctrine adapters + mapping, controllers / Live
   Components, Messenger/Scheduler, security, DI wiring, migrations).
3. **Test tasks** → `qa` agent (Domain unit tests + Application/Feature tests against the real test DB).

After each task: run `make check` and only commit when it is green. Keep commits small (reviewable in
under five minutes) with imperative messages. Check off tasks in `task-list.md` as you go.

When the task list is complete, tell the user and suggest running `/verify $1`.
