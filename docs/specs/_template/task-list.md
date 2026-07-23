# Task List: <feature name>

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/<name>`. Run `make check` before each commit. Check off as you go.

## Domain
- [ ] T1: <value object(s)>
- [ ] T2: <aggregate/entity + invariants>
- [ ] T3: <domain event(s)>
- [ ] T4: <port interface>

## Application
- [ ] T5: <command/query + handler>

## Infrastructure
- [ ] T6: <Doctrine adapter + mapping>
- [ ] T7: <migration>
- [ ] T8: <controller / Live Component + route>
- [ ] T9: <DI wiring (port → adapter)>
- [ ] T10: <async / schedule / external adapter if any>

## Tests (qa)
- [ ] T11: <Domain unit tests>
- [ ] T12: <Application/Feature tests>
- [ ] T13: <performance check if applicable>

## Verify
- [ ] T14: `/verify` → reviewer PASS, all acceptance criteria checked, docs updated.
