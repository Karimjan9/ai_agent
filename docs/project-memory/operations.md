---
aliases:
  - Operations and Validation
tags:
  - operations
  - testing
updated: 2026-07-12
---

# Operations and Validation

## Service locations

- Laravel application: `backend-laravel/`
- Python service: `ai-service-python/`
- Historical datasets: `datasets/`

## Focused validation

Run tests from the component that changed:

```powershell
Set-Location ai-service-python
python -m unittest discover -s tests

Set-Location ../backend-laravel
php artisan test --filter=<RelevantTestName>
```

Use `php artisan test` only when broad regression coverage is warranted. API/service field expectations are documented in `docs/ai-service-contract.md`.

## Update checklist

- New route/controller/service: update [[modules]] and `project-index.json`.
- New externally visible Python endpoint: update [[architecture]], `project-index.json`, and the API contract.
- New migration/model: add the affected module's persistence note.
- Finished implementation: record the date and test command/result in the relevant module note or `change_log`.

## Scope warning

The repository currently has many uncommitted implementation changes. Before modifying code, inspect the target file's diff and preserve unrelated work.
