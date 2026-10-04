#!/usr/bin/env node

/** Opt-in, wrapper-only navigation benchmark; never records file contents. */
import { randomUUID } from "node:crypto";
import { spawnSync } from "node:child_process";
import { appendFileSync, existsSync, mkdirSync, readFileSync, realpathSync, statSync } from "node:fs";
import { dirname, relative, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const rootReal = realpathSync(root);
const defaultLog = resolve(root, ".project-map", ".navigation-metrics", "events.jsonl");
const logPath = process.env.PROJECT_MAP_METRICS_FILE || defaultLog;
const excludedParts = new Set([".git", "vendor", "storage", "node_modules", ".runtime", "runtime",
    ".navigation-metrics", ".venv", "venv", "__pycache__", ".aws", ".codex"]);
const fallbackReasons = ["missing_anchor", "missing_test_link", "stale_map", "scope_miss", "other"];

function options() {
    const result = {};
    for (const arg of process.argv.slice(3)) {
        if (!arg.startsWith("--")) throw new Error("Options must use --name=value.");
        const index = arg.indexOf("=");
        const key = index < 0 ? arg.slice(2) : arg.slice(2, index);
        const value = index < 0 ? true : arg.slice(index + 1);
        if (key === "scope") result.scope = [...(result.scope || []), value];
        else if (key in result) throw new Error(`Duplicate --${key} option.`);
        else result[key] = value;
    }
    return result;
}

function integerOption(value, name, defaultValue, maximum = Number.MAX_SAFE_INTEGER) {
    if (value === undefined) return defaultValue;
    if (!/^\d+$/.test(String(value)) || Number(value) < 1 || Number(value) > maximum) {
        throw new Error(`--${name} must be an integer between 1 and ${maximum}.`);
    }
    return Number(value);
}

function events() {
    if (!existsSync(logPath)) return [];
    return readFileSync(logPath, "utf8").split(/\r?\n/).filter(Boolean).map((line) => JSON.parse(line));
}

function append(event) {
    mkdirSync(dirname(logPath), { recursive: true });
    appendFileSync(logPath, `${JSON.stringify({ ...event, at: Date.now() })}\n`, "utf8");
}

function sessionRecord(rows, id) {
    const start = rows.find((row) => row.type === "start" && row.session === id);
    if (!start) throw new Error("Unknown benchmark session.");
    if (rows.some((row) => row.type === "finish" && row.session === id)) {
        throw new Error("Benchmark session is already finished.");
    }
    return start;
}

function safePath(input) {
    if (typeof input !== "string" || !input) throw new Error("A workspace-relative path is required.");
    const lexicalPath = resolve(root, input);
    const path = realpathSync(lexicalPath);
    for (const [base, candidate] of [[root, lexicalPath], [rootReal, path]]) {
        if (candidate !== base && !candidate.startsWith(`${base}${sep}`)) {
            throw new Error("Path must stay inside the workspace.");
        }
        const parts = relative(base, candidate).split(sep).map((part) => part.toLowerCase());
        if (parts.some((part) => excludedParts.has(part) || part.startsWith(".env"))
            || /\.(pem|key|pfx|p12)$/i.test(candidate)) {
            throw new Error("Sensitive or generated paths are excluded from navigation benchmarks.");
        }
    }
    return { path, relativePath: relative(rootReal, path).split(sep).join("/") || "." };
}

export function selectLines(content, from, to) {
    const lines = content.toString("utf8").match(/[^\n]*\n|[^\n]+$/g) || [];
    if (from === undefined && to === undefined) {
        return { content, from: lines.length ? 1 : 0, to: lines.length, total_lines: lines.length };
    }
    const first = integerOption(from, "from", 1);
    const last = integerOption(to, "to", lines.length);
    if (first > last || first > lines.length) throw new Error("Read range is empty or reversed.");
    return { content: Buffer.from(lines.slice(first - 1, last).join(""), "utf8"),
        from: first, to: Math.min(last, lines.length), total_lines: lines.length };
}

function median(values) {
    if (!values.length) return null;
    const sorted = [...values].sort((a, b) => a - b);
    const mid = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}

export function summarize(rows) {
    const sessions = new Map();
    for (const row of rows) {
        if (row.type === "start") {
            sessions.set(row.session, { caseId: row.case, mode: row.mode, startedAt: row.at,
                kind: row.kind || "navigation", searches: 0, readBytes: 0, searchBytes: 0,
                reads: [], truncatedSearches: 0, fallbacks: [], firstHitAt: null, firstHitPath: null,
                firstHitInspected: false, finishedAt: null, tokens: null });
            continue;
        }
        const session = sessions.get(row.session);
        if (!session || session.finishedAt !== null) continue;
        if (row.type === "search") {
            session.searches += 1;
            session.searchBytes += row.bytes || 0;
            if (row.truncated) session.truncatedSearches += 1;
        }
        if (row.type === "read") {
            session.readBytes += row.bytes;
            session.reads.push({ path: row.path, from: row.from ?? null, to: row.to ?? null, bytes: row.bytes });
        }
        if (row.type === "fallback") session.fallbacks.push({ reason: row.reason, path: row.path });
        if (row.type === "hit" && session.firstHitAt === null) {
            session.firstHitAt = row.at;
            session.firstHitPath = row.path || null;
            session.firstHitInspected = Boolean(row.path && !row.path.startsWith(".project-map/")
                && session.reads.some((read) => read.path === row.path && read.bytes > 0));
        }
        if (row.type === "finish") {
            session.finishedAt = row.at;
            session.tokens = Number.isFinite(row.tokens) ? row.tokens : null;
        }
    }
    const byCase = new Map();
    for (const session of sessions.values()) {
        if (session.finishedAt === null || session.firstHitAt === null) continue;
        const modes = byCase.get(session.caseId) || {};
        if (!modes[session.mode]) modes[session.mode] = session;
        byCase.set(session.caseId, modes);
    }
    const candidatePairs = [...byCase.entries()].filter(([, modes]) => modes.map && modes.baseline);
    const comparable = (modes) => modes.map.firstHitInspected && modes.baseline.firstHitInspected
        && modes.map.firstHitPath === modes.baseline.firstHitPath;
    const pairs = candidatePairs.filter(([, modes]) => comparable(modes))
        .map(([caseId, modes]) => ({ caseId, map: modes.map, baseline: modes.baseline }));
    const comparison = (metric) => {
        const differences = pairs.map(({ map, baseline }) => metric(baseline) - metric(map));
        return { median_baseline_minus_map: median(differences),
            map_better_cases: differences.filter((value) => value > 0).length,
            tied_cases: differences.filter((value) => value === 0).length };
    };
    const tokenPairs = pairs.filter(({ map, baseline }) => map.tokens !== null && baseline.tokens !== null);
    return {
        protocol: "project_map_navigation_benchmark_v2",
        status: pairs.length >= 10 ? "descriptive_sample_ready" : "insufficient_paired_cases",
        completed_paired_cases: pairs.length,
        required_paired_cases: 10,
        sessions_recorded: sessions.size,
        completed_sessions: [...sessions.values()].filter((session) => session.finishedAt !== null).length,
        incomplete_sessions: [...sessions.values()].filter((session) => session.finishedAt === null).length,
        source_mismatch_or_unverified_cases: candidatePairs.filter(([, modes]) => !comparable(modes)).map(([caseId]) => caseId),
        search_count: comparison((session) => session.searches),
        file_bytes_read: comparison((session) => session.readBytes),
        search_result_bytes: comparison((session) => session.searchBytes),
        content_bytes_seen: comparison((session) => session.readBytes + session.searchBytes),
        seconds_to_first_source: comparison((session) => (session.firstHitAt - session.startedAt) / 1000),
        total_seconds: comparison((session) => (session.finishedAt - session.startedAt) / 1000),
        reported_token_pairs: tokenPairs.length,
        token_difference: tokenPairs.length ? median(tokenPairs.map(({ map, baseline }) => baseline.tokens - map.tokens)) : null,
        session_details: [...sessions.entries()].map(([id, session]) => ({ session: id,
            case: session.caseId, mode: session.mode, kind: session.kind,
            finished: session.finishedAt !== null, first_source: session.firstHitPath,
            source_inspected_before_hit: session.firstHitInspected,
            searches: session.searches, reads: session.reads, content_bytes_seen: session.readBytes + session.searchBytes,
            truncated_searches: session.truncatedSearches, fallbacks: session.fallbacks })),
        case_details: pairs.map(({ caseId, map, baseline }) => ({ case: caseId, first_source: map.firstHitPath,
            first_mode: map.startedAt <= baseline.startedAt ? "map" : "baseline",
            content_bytes_seen: { map: map.readBytes + map.searchBytes, baseline: baseline.readBytes + baseline.searchBytes },
            searches: { map: map.searches, baseline: baseline.searches } })),
        limitations: "Only wrapper-emitted content bytes/searches and self-reported first correct source are measured, not physical disk I/O. Both modes must name the same source. Truncation, order/learning bias and wrapper/coordination overhead affect results. Paired read-only cases are descriptive, not proof of causal savings or actual API billing.",
    };
}

function main() {
    const command = process.argv[2];
    const args = options();
    if (command === "report") {
        process.stdout.write(`${JSON.stringify(summarize(events()), null, 2)}\n`);
        return;
    }
    if (command === "start") {
        if (!/^[a-zA-Z0-9_.-]{1,80}$/.test(String(args.case || ""))
            || !["map", "baseline"].includes(args.mode)
            || !["bug", "runtime", "lifecycle", "feature", "navigation"].includes(args.kind || "navigation")) {
            throw new Error("Use start --case=ID --mode=map|baseline [--kind=bug|runtime|lifecycle|feature|navigation].");
        }
        const session = randomUUID();
        append({ type: "start", session, case: args.case, mode: args.mode, kind: args.kind || "navigation" });
        process.stdout.write(`${JSON.stringify({ session, case: args.case, mode: args.mode })}\n`);
        return;
    }
    const id = String(args.session || "");
    const rows = events();
    const session = sessionRecord(rows, id);
    if (command === "read") {
        const target = safePath(args.path);
        if (!statSync(target.path).isFile()) throw new Error("Read target must be a file.");
        const selected = selectLines(readFileSync(target.path), args.from, args.to);
        append({ type: "read", session: id, path: target.relativePath, bytes: selected.content.length,
            from: selected.from, to: selected.to, total_lines: selected.total_lines });
        process.stdout.write(selected.content);
    } else if (command === "search") {
        if (!args.pattern) throw new Error("Use search --pattern=TEXT [--scope=PATH].");
        const scopes = [...new Set((args.scope || ["."]).map((scope) => safePath(scope).relativePath))];
        const limit = integerOption(args["max-results"], "max-results", 80, 500);
        const context = args.context === "0" || args.context === undefined ? 0 : integerOption(args.context, "context", 0, 8);
        const excludes = [...excludedParts].flatMap((part) => ["--iglob", `!**/${part}/**`]);
        const result = spawnSync("rg", ["--no-config", "-n", "--hidden", "--no-follow", "--color=never", `--context=${context}`,
            ...excludes, "--iglob", "!**/.env*", "--iglob", "!**/*.{pem,key,pfx,p12}", "--", String(args.pattern), ...scopes],
            { cwd: root, encoding: "utf8", maxBuffer: 8 * 1024 * 1024 });
        if (result.error || ![0, 1].includes(result.status)) throw result.error || new Error(result.stderr);
        const lines = (result.stdout || "").match(/[^\n]*\n|[^\n]+$/g) || [];
        const output = lines.slice(0, limit).join("");
        const truncated = lines.length > limit;
        append({ type: "search", session: id, scopes, context,
            bytes: Buffer.byteLength(output, "utf8"), result_lines: Math.min(lines.length, limit),
            available_lines: lines.length, truncated });
        process.stdout.write(output);
        if (truncated) process.stderr.write(`Search truncated to ${limit}/${lines.length} output lines; narrow the scope/pattern.\n`);
    } else if (command === "hit") {
        const target = safePath(args.path);
        if (!statSync(target.path).isFile()) throw new Error("Hit target must be a file.");
        if (target.relativePath.startsWith(".project-map/")
            || !rows.some((row) => row.session === id && row.type === "read"
                && row.path === target.relativePath && row.bytes > 0)) {
            throw new Error("Read the actual source before reporting a hit; a map entry is not a source hit.");
        }
        append({ type: "hit", session: id, path: target.relativePath });
        process.stdout.write(`${target.relativePath}\n`);
    } else if (command === "fallback") {
        if (session.mode !== "map" || !fallbackReasons.includes(args.reason)) {
            throw new Error(`Map sessions may record fallback --reason=${fallbackReasons.join("|")} --path=PATH.`);
        }
        const target = safePath(args.path);
        append({ type: "fallback", session: id, reason: args.reason, path: target.relativePath });
        process.stdout.write(`${JSON.stringify({ reason: args.reason, path: target.relativePath })}\n`);
    } else if (command === "finish") {
        const tokens = args.tokens === undefined ? null : Number(args.tokens);
        if (tokens !== null && (!Number.isInteger(tokens) || tokens < 0)) {
            throw new Error("Optional --tokens must be a non-negative integer from actual usage telemetry.");
        }
        append({ type: "finish", session: id, tokens });
        process.stdout.write(`${JSON.stringify({ session: id, finished: true })}\n`);
    } else {
        throw new Error("Usage: measure.mjs <start|read|search|hit|fallback|finish|report> [--case= --mode= --kind= --session= --path= --from= --to= --pattern= --scope= --max-results= --context= --reason= --tokens=]");
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        main();
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}
