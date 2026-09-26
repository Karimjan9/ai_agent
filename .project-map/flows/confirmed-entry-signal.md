# Cross-module flow: confirmed entry signal

## Purpose

Turn closed multi-timeframe inputs into an explicit and attestable execution
decision that can later be observed by the paper lifecycle.

```text
Canonical closed H4/H1/M15/M5 data
  -> Python context/location/setup/confirmation/trigger compiler
  -> Signal-close geometry admission or WAIT
  -> Laravel verifies sealed projection
  -> Fill-time geometry re-admission or WAIT
  -> Paper-execution intake
```

## Modules

- `market-data`: provides the attributable closed inputs.
- `confirmation-entry`: owns signal compilation and first/fill-time admission.
- `laravel-python-contract`: carries the sealed request/projection boundary.
- `paper-execution`: consumes only an attested eligible signal.

## Invariants

- Context, setup, confirmation and trigger are distinct steps.
- Missing/invalid temporal evidence becomes `WAIT`.
- Fill-time R:R and chase checks may invalidate a previously valid signal.
- Paper and replay use the same compiler semantics.
