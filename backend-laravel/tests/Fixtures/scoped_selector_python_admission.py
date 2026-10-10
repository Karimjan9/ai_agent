"""Actual PHP request admission; optional real synthetic replay, never market authority."""
import json
import os
import sys
from pathlib import Path
from unittest.mock import patch

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import _composition_runtime_authority, _scoped_maturity_entry_end, prepare_replay_feature_context
from app.services.composition_runtime import validate_composition_runtime_contract
from app.services.research_release import verify_research_transport
from app.services.scoped_research_runtime import scoped_research_runtime_current


def main() -> None:
    payload = SimpleBacktestRequest.model_validate(json.loads(Path(sys.argv[1]).read_text(encoding="utf-8")))
    # Simulates the same future observation date as Carbon's test clock. No admission/attestation is mocked.
    observation = pd.Timestamp(sys.argv[2])
    with patch("app.services.research_release._research_transport_now", return_value=observation):
        transport = verify_research_transport(payload, os.environ["SCOPED_SELECTOR_FIXTURE_INTERNAL_KEY"])
        assert transport is not None
        purpose = payload.policy_context["scoped_research_certificate"]["purpose"]
        selector = purpose == "independent_scoped_selector_research"
        assert purpose in {"independent_scoped_component_research", "independent_scoped_descendant_research", "independent_scoped_selector_research"}
        assert payload.evaluation_mode == "full"
        assert len(payload.strategies) == (3 if selector else 1)
        assert set(payload.mtf_dataset_paths) == {"M5", "H4", "H1", "M15"}
        assert payload.foundation_dataset_path is None
        assert "data_boundary" not in payload.policy_context
        assert _scoped_maturity_entry_end(payload) is not None
        assert scoped_research_runtime_current(payload)
        from app import main as native_main
        assert native_main._scoped_full_durable_confirmation(payload) is selector
        contracts = payload.policy_context.get("learning_confirmation_contracts", {})
        programmes = 0
        for config in payload.strategies:
            parameters = (dict(config.parameters) if config.specialist_council_contract else
                native_main.validate_strategy_parameters(config.strategy, config.parameters, config.base_strategy))
            arm = payload.model_copy(update={
                "strategy": config.strategy,
                "base_strategy": config.base_strategy,
                "version": config.version,
                "parameters": parameters,
                "instrument_research_assignment": config.instrument_research_assignment,
                "composition_runtime_contract": config.composition_runtime_contract,
                "specialist_context_contract": config.specialist_context_contract,
                "specialist_council_contract": config.specialist_council_contract,
                "strategies": [],
            })
            if selector:
                cap = contracts[str(config.lab_agent_id)]
                assert cap["execution_mode"] == "durable_single_fold_job"
                assert cap["fold_count"] == 1
                assert cap["per_fold_budget_seconds"] == 45
            assert scoped_research_runtime_current(arm)
            context = prepare_replay_feature_context(arm)
            assert context.mtf_context is not None and context.mtf_context.status == "ready"
            validated = validate_composition_runtime_contract(config.composition_runtime_contract,
                base_strategy=config.base_strategy or config.strategy, parameters=parameters,
                execution_timeframe=arm.timeframe, runtime_authority=_composition_runtime_authority(arm))
            if validated:
                assert config.instrument_research_assignment
                programmes += 1
        proof = {"schema": True, "transport": True, "maturity_fence": True, "closed_mtf_context": True,
            "original_arm_count": len(payload.strategies), "compiled_programme_count": programmes,
            "promotion_evidence": False}
        if "--native-replay" in sys.argv[3:]:
            # Real evaluator and original aggregation. No response/safety/readiness values are substituted.
            result = native_main._run_all_backtests_sync(payload)
            original_path = Path(sys.argv[1]).with_suffix(".original-response.json")
            original_path.write_text(json.dumps(result, separators=(",", ":")), encoding="utf-8")
            items = result["leaderboard"]
            assert len(items) == len(payload.strategies)
            assert {item["lab_agent_id"] for item in items} == {config.lab_agent_id for config in payload.strategies}
            rows = []; decisions = []
            for item in items:
                response = item["result"]
                clock = response["data_quality"]["replay_executed_clock"]
                assert clock["complete"] is True and clock["input_rows"] > 200
                assert clock["decision_rows"] == clock["input_rows"] - 200
                maturity = response["statistical_evidence"]["original_position_maturity"]
                assert maturity["protocol"] == "original_position_maturity_v1"
                assert maturity["forced_terminal_close_applied"] is False
                rows.append(clock["input_rows"]); decisions.append(clock["decision_rows"])
                if selector:
                    assert item["rolling_windows_count"] == 1
                    assert response["learning_confirmation"]["execution_mode"] == "durable_single_fold_job"
                    cap = contracts[str(item["lab_agent_id"])]
                    for field in ("fold_universe_count", "max_rows_per_fold", "per_fold_budget_seconds"):
                        assert response["learning_confirmation"][field] == cap[field]
                    resource = response["benchmark"]["arm_replay_resources"]
                    assert resource["measured_segments"] == 1 and resource["wall_seconds"] > 0
            proof.update({"native_original_full_replay": True, "native_input_rows": rows[0],
                "native_executed_clock_rows": decisions[0], "original_response_path": str(original_path)})
            if selector:
                aggregate = native_main.aggregate_causal_folds({"expected_fold_count": 1, "fold_receipts": [result]})
                assert aggregate["received_fold_count"] == 1 and len(aggregate["leaderboard"]) == 3
                aggregate_path = Path(sys.argv[1]).with_suffix(".original-aggregate.json")
                aggregate_path.write_text(json.dumps(aggregate, separators=(",", ":")), encoding="utf-8")
                proof.update({"native_durable_fold": True, "actual_resource_arm_count": len(items),
                    "original_aggregate_path": str(aggregate_path)})
        print(json.dumps(proof, separators=(",", ":")))


if __name__ == "__main__":
    main()
