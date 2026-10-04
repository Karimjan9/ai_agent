import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const runtime = JSON.parse(readFileSync(resolve(fileURLToPath(new URL(".", import.meta.url)), "..", "..",
    ".project-map", "generated", "runtime-index.json"), "utf8"));

test("runtime index points to configuration sections and process owners", () => {
    const services = runtime.configs.find((row) => row.path === "backend-laravel/config/services.php");
    assert.ok(services);
    assert.ok(services.sections.some((row) => row.key === "xauusd_organism" && row.line > 0));
    assert.ok(services.sections.some((row) => row.key === "lab_selection" && row.line > 0));
    assert.ok(runtime.scripts.some((row) => row.path === "backend-laravel/scripts/run-laravel-workers-hidden.ps1"));
    assert.ok(runtime.scripts.some((row) => row.path === "backend-laravel/scripts/audit-research-release.php"));
});
