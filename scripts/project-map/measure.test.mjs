import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, relative, resolve } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { selectLines, summarize } from "./measure.mjs";

const repository = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const script = join(repository, "scripts", "project-map", "measure.mjs");

function fixture(t) {
    const parent = join(repository, "scripts", "project-map", ".navigation-fixtures");
    mkdirSync(parent, { recursive: true });
    const directory = mkdtempSync(join(parent, "measure-"));
    t.after(() => {
        assert.ok(directory.startsWith(join(parent, "measure-")));
        rmSync(directory, { recursive: true, force: true });
    });
    const log = join(directory, "events.jsonl");
    const path = (name) => relative(repository, join(directory, name)).split("\\").join("/");
    const write = (name, content) => {
        const target = join(directory, name);
        mkdirSync(dirname(target), { recursive: true });
        writeFileSync(target, content, "utf8");
        return path(name);
    };
    const cli = (...args) => spawnSync(process.execPath, [script, ...args], { cwd: repository,
        env: { ...process.env, PROJECT_MAP_METRICS_FILE: log }, encoding: "utf8" });
    const start = (mode = "map") => {
        const result = cli("start", "--case=cli-test", `--mode=${mode}`, "--kind=runtime");
        assert.equal(result.status, 0, result.stderr);
        return JSON.parse(result.stdout).session;
    };
    const events = () => existsSync(log) ? readFileSync(log, "utf8").trim().split("\n").map(JSON.parse) : [];
    return { directory, log, path, write, cli, start, events };
}

test("paired navigation report distinguishes measured bytes from unavailable tokens", () => {
    const rows = [
        { type: "start", session: "m", case: "same-question", mode: "map", at: 0 },
        { type: "read", session: "m", path: "app/source.php", bytes: 100, at: 1 },
        { type: "hit", session: "m", path: "app/source.php", at: 2000 },
        { type: "finish", session: "m", tokens: null, at: 3000 },
        { type: "start", session: "b", case: "same-question", mode: "baseline", at: 0 },
        { type: "search", session: "b", bytes: 40, at: 1 },
        { type: "search", session: "b", bytes: 20, at: 2 },
        { type: "read", session: "b", path: "app/source.php", bytes: 500, at: 3 },
        { type: "hit", session: "b", path: "app/source.php", at: 5000 },
        { type: "finish", session: "b", tokens: null, at: 8000 },
    ];
    const result = summarize(rows);
    assert.equal(result.status, "insufficient_paired_cases");
    assert.equal(result.completed_paired_cases, 1);
    assert.equal(result.search_count.median_baseline_minus_map, 2);
    assert.equal(result.file_bytes_read.median_baseline_minus_map, 400);
    assert.equal(result.search_result_bytes.median_baseline_minus_map, 60);
    assert.equal(result.content_bytes_seen.median_baseline_minus_map, 460);
    assert.equal(result.seconds_to_first_source.median_baseline_minus_map, 3);
    assert.equal(result.reported_token_pairs, 0);
    assert.equal(result.token_difference, null);
});

test("different or legacy-unverified first-source hits cannot produce savings", () => {
    const rows = [
        { type: "start", session: "m", case: "mismatch", mode: "map", at: 0 },
        { type: "hit", session: "m", path: "app/one.php", at: 1 },
        { type: "finish", session: "m", at: 2 },
        { type: "start", session: "b", case: "mismatch", mode: "baseline", at: 0 },
        { type: "hit", session: "b", path: "app/two.php", at: 1 },
        { type: "finish", session: "b", at: 2 },
    ];
    for (const input of [rows, rows.map(({ path, ...row }) => row)]) {
        const result = summarize(input);
        assert.equal(result.completed_paired_cases, 0);
        assert.deepEqual(result.source_mismatch_or_unverified_cases, ["mismatch"]);
        assert.equal(result.content_bytes_seen.median_baseline_minus_map, null);
    }
});

test("only ten comparable completed pairs produce a descriptive sample", () => {
    const rows = Array.from({ length: 10 }, (_, index) => ["map", "baseline"].flatMap((mode) => [
        { type: "start", session: `${index}-${mode}`, case: `case-${index}`, mode, at: 0 },
        { type: "read", session: `${index}-${mode}`, path: `app/source-${index}.php`, bytes: 10, at: 0.5 },
        { type: "hit", session: `${index}-${mode}`, path: `app/source-${index}.php`, at: 1 },
        { type: "finish", session: `${index}-${mode}`, at: 2 },
    ])).flat();
    const report = summarize(rows);
    assert.equal(report.status, "descriptive_sample_ready");
    assert.equal(report.completed_paired_cases, 10);
    assert.equal(report.token_difference, null);
    assert.equal(report.case_details.length, 10);
    assert.match(report.limitations, /not proof.*API billing/);
});

test("a late read cannot retroactively verify a first-source hit", () => {
    const rows = ["map", "baseline"].flatMap((mode) => [
        { type: "start", session: mode, case: "late-read", mode, at: 0 },
        { type: "hit", session: mode, path: "app/source.php", at: 1 },
        { type: "read", session: mode, path: "app/source.php", bytes: 100, at: 2 },
        { type: "finish", session: mode, at: 3 },
    ]);
    const report = summarize(rows);
    assert.equal(report.completed_paired_cases, 0);
    assert.deepEqual(report.source_mismatch_or_unverified_cases, ["late-read"]);
    assert.equal(report.session_details[0].source_inspected_before_hit, false);
});

test("line ranges are inclusive and preserve real UTF-8 output bytes", () => {
    const selected = selectLines(Buffer.from("one\r\nikki\r\nuch —\r\nfour"), "2", "3");
    assert.equal(selected.content.toString(), "ikki\r\nuch —\r\n");
    assert.equal(selected.from, 2);
    assert.equal(selected.to, 3);
    assert.equal(selected.total_lines, 4);
    assert.throws(() => selectLines(Buffer.from("one\ntwo"), "2", "1"), /empty or reversed/);
    assert.throws(() => selectLines(Buffer.from("one\ntwo"), "3"), /empty or reversed/);
    assert.throws(() => selectLines(Buffer.from("one\ntwo"), "0"), /integer/);
    assert.equal(selectLines(Buffer.from("one\ntwo"), "2", "999").content.toString(), "two");
    assert.equal(selectLines(Buffer.alloc(0)).content.length, 0);
});

test("CLI records selected ranges, requires inspected source hit, and rejects closed sessions", (t) => {
    const f = fixture(t);
    const source = f.write("source.txt", "first\nsecond —\nthird\n");
    const session = f.start();
    assert.notEqual(f.cli("hit", `--session=${session}`, `--path=${source}`).status, 0);
    const read = f.cli("read", `--session=${session}`, `--path=${source}`, "--from=2", "--to=2");
    assert.equal(read.status, 0, read.stderr);
    assert.equal(read.stdout, "second —\n");
    assert.equal(f.events().find((row) => row.type === "read").bytes, Buffer.byteLength(read.stdout));
    const hit = f.cli("hit", `--session=${session}`, `--path=${source}`);
    assert.equal(hit.status, 0, hit.stderr);
    assert.equal(f.cli("finish", `--session=${session}`, "--tokens=25").status, 0);
    assert.notEqual(f.cli("read", `--session=${session}`, `--path=${source}`).status, 0);
    const report = JSON.parse(f.cli("report").stdout);
    assert.equal(report.session_details[0].reads[0].from, 2);
    assert.equal(report.session_details[0].kind, "runtime");
    assert.equal(report.completed_sessions, 1);
});

test("search keeps repeated scopes, caps total output and records truncation", (t) => {
    const f = fixture(t);
    const a = f.write("first.txt", "needle first\nneedle second\nneedle third\n");
    const b = f.write("second.txt", "needle fourth\n");
    const session = f.start();
    const result = f.cli("search", `--session=${session}`, "--pattern=needle", `--scope=${a}`, `--scope=${b}`, "--max-results=2");
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stdout.trimEnd().split("\n").length, 2);
    assert.match(result.stderr, /truncated/);
    const event = f.events().find((row) => row.type === "search");
    assert.deepEqual(event.scopes, [a, b]);
    assert.equal(event.truncated, true);
    assert.equal(event.available_lines, 4);
    assert.equal(event.bytes, Buffer.byteLength(result.stdout));
    assert.equal(JSON.parse(f.cli("report").stdout).session_details[0].truncated_searches, 1);
    const full = f.cli("search", `--session=${session}`, "--pattern=needle", `--scope=${a}`, `--scope=${b}`, "--max-results=8");
    assert.match(full.stdout, /needle fourth/);
});

test("search ignores hostile rg configuration and sensitive recursive descendants", (t) => {
    const f = fixture(t);
    const publicPath = f.write("public.txt", "needle public\n");
    for (const name of [".env", ".ENV.local", ".git/private.txt", "storage/private.txt", "node_modules/private.txt",
        "vendor/private.txt", ".runtime/private.txt", ".VENV/private.txt", "secret.PEM"]) {
        f.write(name, "needle private\n");
    }
    f.write("rg-config.txt", "--hidden\n--no-ignore\n--follow\n");
    const session = f.start();
    const result = spawnSync(process.execPath, [script, "search", `--session=${session}`, "--pattern=needle", `--scope=${f.path("")}`],
        { cwd: repository, env: { ...process.env, PROJECT_MAP_METRICS_FILE: f.log,
            RIPGREP_CONFIG_PATH: join(f.directory, "rg-config.txt") }, encoding: "utf8" });
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /needle public/);
    assert.doesNotMatch(result.stdout, /needle private/);
    const before = f.events().filter((row) => row.type === "search").length;
    assert.notEqual(f.cli("search", `--session=${session}`, "--pattern=needle", `--scope=${publicPath}`, `--scope=${f.path(".ENV.local")}`).status, 0);
    assert.notEqual(f.cli("read", `--session=${session}`, `--path=${f.path(".env")}`).status, 0);
    assert.equal(f.events().filter((row) => row.type === "search").length, before);
});

test("outside-workspace paths and symlinks are denied and recursive search never follows them", (t) => {
    const f = fixture(t);
    const external = mkdtempSync(join(tmpdir(), "map-navigation-test-"));
    t.after(() => {
        assert.ok(external.startsWith(join(tmpdir(), "map-navigation-test-")));
        rmSync(external, { recursive: true, force: true });
    });
    writeFileSync(join(external, "outside.txt"), "needle outside\n");
    const session = f.start();
    assert.notEqual(f.cli("read", `--session=${session}`, `--path=${join(external, "outside.txt")}`).status, 0);
    symlinkSync(external, join(f.directory, "outside-link"), process.platform === "win32" ? "junction" : "dir");
    assert.notEqual(f.cli("read", `--session=${session}`, `--path=${f.path("outside-link/outside.txt")}`).status, 0);
    const result = f.cli("search", `--session=${session}`, "--pattern=needle", `--scope=${f.path("")}`);
    assert.equal(result.status, 0, result.stderr);
    assert.doesNotMatch(result.stdout, /needle outside/);
});

test("categorical fallback metadata is preserved without logging file contents or patterns", (t) => {
    const f = fixture(t);
    const source = f.write("source.txt", "distinctive-private-test-content\n");
    const session = f.start();
    const result = f.cli("fallback", `--session=${session}`, "--reason=scope_miss", `--path=${source}`);
    assert.equal(result.status, 0, result.stderr);
    assert.notEqual(f.cli("fallback", `--session=${session}`, "--reason=free secret text", `--path=${source}`).status, 0);
    assert.equal(f.cli("read", `--session=${session}`, `--path=${source}`).status, 0);
    assert.equal(f.cli("search", `--session=${session}`, "--pattern=distinctive-private-test-content", `--scope=${source}`).status, 0);
    assert.doesNotMatch(readFileSync(f.log, "utf8"), /distinctive-private-test-content/);
    const report = JSON.parse(f.cli("report").stdout);
    assert.deepEqual(report.session_details[0].fallbacks, [{ reason: "scope_miss", path: source }]);
    assert.equal(report.incomplete_sessions, 1);
    assert.equal(report.completed_paired_cases, 0);
});

test("report is read-only and does not create a missing event log", (t) => {
    const f = fixture(t);
    assert.equal(existsSync(f.log), false);
    const result = f.cli("report");
    assert.equal(result.status, 0, result.stderr);
    assert.equal(existsSync(f.log), false);
    assert.equal(JSON.parse(result.stdout).sessions_recorded, 0);
});

test("unpaired or unfinished sessions do not manufacture a savings claim", () => {
    const result = summarize([
        { type: "start", session: "m", case: "one", mode: "map", at: 0 },
        { type: "hit", session: "m", at: 1000 },
        { type: "finish", session: "m", at: 2000 },
        { type: "start", session: "b", case: "two", mode: "baseline", at: 0 },
    ]);
    assert.equal(result.completed_paired_cases, 0);
    assert.equal(result.search_count.median_baseline_minus_map, null);
});
