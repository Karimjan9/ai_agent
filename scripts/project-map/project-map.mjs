#!/usr/bin/env node

/**
 * Small, dependency-free Project Map generator/checker.
 *
 * It deliberately produces navigation facts, not an assumed-complete call graph.
 * Laravel containers, events, queues and cross-runtime HTTP calls remain dynamic;
 * source and tests are always the implementation authority.
 */
import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from "node:fs";
import { dirname, relative, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(scriptDirectory, "..", "..");
const mapRoot = resolve(repositoryRoot, ".project-map");
const generatedRoot = resolve(mapRoot, "generated");
const command = process.argv[2];

const generatedFiles = [
    "module-index.json",
    "dependency-graph.json",
    "database-index.json",
    "routes-index.json",
    "symbols-index.json",
    "tests-index.json",
    "integrations-index.json",
    "manifest.json",
];

const sourceRoots = [
    "backend-laravel/app",
    "backend-laravel/routes",
    "backend-laravel/database/migrations",
    "backend-laravel/tests",
    "ai-service-python/app",
    "ai-service-python/tests",
    ".project-map",
];

const sourceExtensions = new Set([".php", ".py", ".yaml"]);
const ignoredDirectories = new Set([
    ".git",
    "node_modules",
    "vendor",
    ".venv",
    "venv",
    "__pycache__",
    ".runtime",
    "runtime",
    "storage",
    "bootstrap",
]);

function normalizedPath(path) {
    return relative(repositoryRoot, path).split(sep).join("/");
}

function readText(path) {
    return readFileSync(path, "utf8");
}

function walk(directory) {
    if (!existsSync(directory)) return [];

    const paths = [];
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        if (entry.isDirectory() && ignoredDirectories.has(entry.name)) continue;
        const entryPath = resolve(directory, entry.name);
        if (entry.isDirectory()) paths.push(...walk(entryPath));
        if (entry.isFile()) paths.push(entryPath);
    }
    return paths;
}

function sourceFiles() {
    return sourceRoots
        .flatMap((root) => walk(resolve(repositoryRoot, root)))
        .filter((path) => sourceExtensions.has(path.slice(path.lastIndexOf("."))))
        .sort((left, right) => normalizedPath(left).localeCompare(normalizedPath(right)));
}

function sourceDigest(files = sourceFiles()) {
    const hash = createHash("sha256");
    for (const file of files) {
        hash.update(normalizedPath(file));
        hash.update("\0");
        hash.update(readFileSync(file));
        hash.update("\0");
    }
    return hash.digest("hex");
}

function fileDigest(path) {
    return createHash("sha256").update(readFileSync(path)).digest("hex");
}

function lineNumber(text, offset) {
    return text.slice(0, offset).split("\n").length;
}

function gitHead() {
    try {
        return execFileSync("git", ["rev-parse", "HEAD"], {
            cwd: repositoryRoot,
            encoding: "utf8",
            stdio: ["ignore", "pipe", "ignore"],
        }).trim();
    } catch {
        return null;
    }
}

function changedFiles(base = "HEAD") {
    try {
        const tracked = execFileSync("git", ["diff", "--name-only", "--diff-filter=ACMR", base, "--"], {
            cwd: repositoryRoot,
            encoding: "utf8",
            stdio: ["ignore", "pipe", "ignore"],
        });
        const untracked = execFileSync("git", ["ls-files", "--others", "--exclude-standard"], {
            cwd: repositoryRoot,
            encoding: "utf8",
            stdio: ["ignore", "pipe", "ignore"],
        });
        return [...new Set(`${tracked}\n${untracked}`
            .split(/\r?\n/)
            .map((path) => path.trim().replace(/\\/g, "/"))
            .filter(Boolean))];
    } catch {
        return [];
    }
}

/** Parse only the intentionally small YAML subset used by module.yaml. */
function parseModule(path) {
    const scalar = {};
    const lists = {};
    let activeList = null;

    for (const rawLine of readText(path).split(/\r?\n/)) {
        if (!rawLine.trim() || rawLine.trimStart().startsWith("#")) continue;
        const keyMatch = rawLine.match(/^([a-z_]+):\s*(.*?)\s*$/);
        if (keyMatch) {
            const [, key, value] = keyMatch;
            activeList = value === "" ? key : null;
            if (activeList) lists[key] = [];
            else scalar[key] = value.replace(/^['"]|['"]$/g, "");
            continue;
        }
        const itemMatch = rawLine.match(/^\s{2}-\s+(.+?)\s*$/);
        if (itemMatch && activeList) {
            lists[activeList].push(itemMatch[1].replace(/^['"]|['"]$/g, ""));
        }
    }

    return { ...scalar, ...lists, path };
}

function projectModuleIds() {
    const path = resolve(mapRoot, "project.yaml");
    if (!existsSync(path)) return [];
    const ids = [];
    let inModules = false;
    for (const line of readText(path).split(/\r?\n/)) {
        if (/^modules:\s*$/.test(line)) {
            inModules = true;
            continue;
        }
        if (/^[a-z_]+:\s*$/.test(line) && !/^modules:\s*$/.test(line)) {
            inModules = false;
        }
        if (inModules) {
            const match = line.match(/^\s{2}-\s+([a-z0-9-]+)\s*$/);
            if (match) ids.push(match[1]);
        }
    }
    return ids;
}

function projectFlows() {
    const path = resolve(mapRoot, "project.yaml");
    if (!existsSync(path)) return [];
    const flows = [];
    let inFlows = false;
    for (const line of readText(path).split(/\r?\n/)) {
        if (/^cross_module_flows:\s*$/.test(line)) {
            inFlows = true;
            continue;
        }
        if (/^[a-z_]+:\s*$/.test(line) && !/^cross_module_flows:\s*$/.test(line)) {
            inFlows = false;
        }
        if (inFlows) {
            const match = line.match(/^\s{2}-\s+(.+?)\s*$/);
            if (match) flows.push(match[1]);
        }
    }
    return flows;
}

function modules() {
    const directory = resolve(mapRoot, "modules");
    if (!existsSync(directory)) return [];
    return readdirSync(directory, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => parseModule(resolve(directory, entry.name, "module.yaml")))
        .sort((left, right) => String(left.module).localeCompare(String(right.module)));
}

function resolveMapPath(module, mapPath) {
    return resolve(dirname(module.path), mapPath);
}

function validateStructure({ requireGenerated }) {
    const errors = [];
    const requiredRootFiles = ["README.md", "project.yaml", "config.yaml"];
    for (const file of requiredRootFiles) {
        if (!existsSync(resolve(mapRoot, file))) errors.push(`Missing .project-map/${file}`);
    }

    const moduleList = modules();
    const ids = moduleList.map((module) => module.module);
    const expectedIds = projectModuleIds();
    if (!moduleList.length) errors.push("No module.yaml files found.");
    for (const id of expectedIds) {
        if (!ids.includes(id)) errors.push(`project.yaml references unknown module '${id}'.`);
    }
    for (const module of moduleList) {
        const requiredKeys = ["module", "title", "purpose", "paths", "entry_points", "data_tables", "dependencies", "invariants", "flows", "docs", "tests"];
        for (const key of requiredKeys) {
            if (!(key in module) || (Array.isArray(module[key]) && module[key].length === 0 && !["dependencies", "data_tables"].includes(key))) {
                errors.push(`${normalizedPath(module.path)} is missing '${key}'.`);
            }
        }
        if (module.module && module.module !== relative(resolve(mapRoot, "modules"), dirname(module.path)).split(sep)[0]) {
            errors.push(`${normalizedPath(module.path)} module id must match its directory.`);
        }
        for (const sourcePath of module.paths || []) {
            if (!existsSync(resolve(repositoryRoot, sourcePath))) {
                errors.push(`${normalizedPath(module.path)} points to missing source path '${sourcePath}'.`);
            }
        }
        for (const document of module.docs || []) {
            if (!existsSync(resolveMapPath(module, document))) {
                errors.push(`${normalizedPath(module.path)} points to missing document '${document}'.`);
            }
        }
        for (const test of module.tests || []) {
            if (!existsSync(resolve(repositoryRoot, test))) {
                errors.push(`${normalizedPath(module.path)} points to missing test '${test}'.`);
            }
        }
        for (const flow of module.flows || []) {
            if (!existsSync(resolveMapPath(module, flow))) {
                errors.push(`${normalizedPath(module.path)} points to missing flow '${flow}'.`);
            }
        }
        for (const dependency of module.dependencies || []) {
            if (!ids.includes(dependency)) {
                errors.push(`${normalizedPath(module.path)} references unknown dependency '${dependency}'.`);
            }
        }
    }

    const dependenciesByModule = new Map(moduleList.map((module) => [module.module, module.dependencies || []]));
    const visited = new Set();
    const visiting = new Set();
    const visit = (id, trail = []) => {
        if (visiting.has(id)) {
            errors.push(`Project Map dependency cycle: ${[...trail, id].join(" -> ")}.`);
            return;
        }
        if (visited.has(id)) return;
        visiting.add(id);
        for (const dependency of dependenciesByModule.get(id) || []) visit(dependency, [...trail, id]);
        visiting.delete(id);
        visited.add(id);
    };
    for (const id of ids) visit(id);

    for (const flow of projectFlows()) {
        if (!existsSync(resolve(mapRoot, flow))) errors.push(`project.yaml points to missing flow '${flow}'.`);
    }
    for (const directory of ["shared", "decisions", "flows"]) {
        if (!existsSync(resolve(mapRoot, directory))) errors.push(`Missing .project-map/${directory}/`);
    }
    if (requireGenerated) {
        for (const file of generatedFiles) {
            if (!existsSync(resolve(generatedRoot, file))) errors.push(`Missing generated index '${file}'.`);
        }
        for (const module of moduleList) {
            if (!existsSync(resolveMapPath(module, "generated.json"))) {
                errors.push(`${normalizedPath(module.path)} is missing generated.json.`);
            }
        }
    }
    return errors;
}

function fileBelongsToPath(file, sourcePath) {
    const target = resolve(repositoryRoot, sourcePath);
    return file === target || file.startsWith(`${target}${sep}`);
}

function matchAll(expression, text, mapper) {
    const results = [];
    for (const match of text.matchAll(expression)) results.push(mapper(match));
    return results;
}

function indexSymbols(files) {
    const symbols = [];
    for (const file of files.filter((item) => /\.(php|py)$/.test(item))) {
        const text = readText(file);
        const path = normalizedPath(file);
        if (path.endsWith(".php")) {
            symbols.push(...matchAll(/\b(?:abstract\s+|final\s+)?(?:class|interface|trait|enum)\s+(\w+)/g, text, (match) => ({
                symbol: match[1], kind: "php_type", path, line: lineNumber(text, match.index),
            })));
            symbols.push(...matchAll(/\b(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(/g, text, (match) => ({
                symbol: match[1], kind: "php_method", path, line: lineNumber(text, match.index),
            })));
        } else {
            symbols.push(...matchAll(/^\s*class\s+(\w+)/gm, text, (match) => ({
                symbol: match[1], kind: "python_class", path, line: lineNumber(text, match.index),
            })));
            symbols.push(...matchAll(/^\s*(?:async\s+)?def\s+(\w+)\s*\(/gm, text, (match) => ({
                symbol: match[1], kind: "python_function", path, line: lineNumber(text, match.index),
            })));
        }
    }
    return symbols.sort((left, right) => `${left.path}:${left.line}`.localeCompare(`${right.path}:${right.line}`));
}

function indexRoutes(files) {
    const routes = [];
    for (const file of files) {
        const path = normalizedPath(file);
        const text = readText(file);
        if (path.startsWith("backend-laravel/routes/")) {
            routes.push(...matchAll(/Route::(get|post|put|patch|delete|match|any)\(\s*['"]([^'"]+)/g, text, (match) => ({
                runtime: "laravel", method: match[1].toUpperCase(), path: match[2], source: path, line: lineNumber(text, match.index),
            })));
        }
        if (path.startsWith("ai-service-python/app/")) {
            routes.push(...matchAll(/@(?:router|app)\.(get|post|put|patch|delete)\(\s*['"]([^'"]+)/g, text, (match) => ({
                runtime: "fastapi", method: match[1].toUpperCase(), path: match[2], source: path, line: lineNumber(text, match.index),
            })));
        }
    }
    return routes.sort((left, right) => `${left.runtime}:${left.path}:${left.method}`.localeCompare(`${right.runtime}:${right.path}:${right.method}`));
}

function indexDatabase(files) {
    const tables = [];
    const models = [];
    for (const file of files) {
        const path = normalizedPath(file);
        const text = readText(file);
        if (path.startsWith("backend-laravel/database/migrations/")) {
            tables.push(...matchAll(/Schema::(?:create|table)\(\s*['"]([^'"]+)/g, text, (match) => ({
                table: match[1], migration: path, line: lineNumber(text, match.index),
            })));
        }
        if (path.startsWith("backend-laravel/app/Models/") && path.endsWith(".php")) {
            const match = text.match(/\bclass\s+(\w+)/);
            if (match) models.push({ model: match[1], path });
        }
    }
    return { tables: tables.sort((left, right) => left.table.localeCompare(right.table)), models: models.sort((left, right) => left.model.localeCompare(right.model)) };
}

function indexTests(files) {
    const tests = [];
    for (const file of files.filter((item) => /\.(php|py)$/.test(item))) {
        const path = normalizedPath(file);
        if (!path.includes("/tests/")) continue;
        const text = readText(file);
        const names = path.endsWith(".php")
            ? matchAll(/\bfunction\s+(test_\w+|\w+)\s*\(/g, text, (match) => match[1])
            : matchAll(/^\s*def\s+(test_\w+)\s*\(/gm, text, (match) => match[1]);
        tests.push({ path, tests: names });
    }
    return tests.sort((left, right) => left.path.localeCompare(right.path));
}

function moduleTests(module, tests) {
    const selected = new Set(module.tests || []);
    return tests.filter((test) => selected.has(test.path));
}

function indexIntegrations(files, routes) {
    const calls = [];
    for (const file of files.filter((item) => /\.(php|py)$/.test(item))) {
        const path = normalizedPath(file);
        const text = readText(file);
        if (path.endsWith(".php")) {
            calls.push(...matchAll(/\bHttp::(get|post|put|patch|delete)\s*\(/g, text, (match) => ({
                type: "laravel_http_client", method: match[1].toUpperCase(), source: path, line: lineNumber(text, match.index),
            })));
        } else {
            calls.push(...matchAll(/\b(?:requests|httpx)\.(get|post|put|patch|delete)\s*\(/g, text, (match) => ({
                type: "python_http_client", method: match[1].toUpperCase(), source: path, line: lineNumber(text, match.index),
            })));
        }
    }
    return {
        documented_contracts: ["docs/ai-service-contract.md", ".project-map/shared/integrations.md"],
        endpoints: routes.filter((route) => route.runtime === "fastapi"),
        outbound_calls: calls.sort((left, right) => `${left.source}:${left.line}`.localeCompare(`${right.source}:${right.line}`)),
        limitations: "Static discovery only. Container bindings, queues, events and environment-resolved URLs require source and tests for confirmation.",
    };
}

function writeJson(path, value) {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`, "utf8");
}

function generate() {
    const errors = validateStructure({ requireGenerated: false });
    if (errors.length) throw new Error(errors.join("\n"));

    const files = sourceFiles();
    const digest = sourceDigest(files);
    const moduleList = modules();
    const routes = indexRoutes(files);
    const database = indexDatabase(files);
    const symbols = indexSymbols(files);
    const tests = indexTests(files);
    const integrations = indexIntegrations(files, routes);
    const generation = {
        schema_version: 1,
        generator: "scripts/project-map/project-map.mjs",
        generator_digest: fileDigest(resolve(scriptDirectory, "project-map.mjs")),
        generated_at: new Date().toISOString(),
        source_digest: digest,
        source_file_count: files.length,
        git_head: gitHead(),
        authority: "Navigation facts only. Real source code and tests remain authoritative.",
    };

    const indexedModules = moduleList.map((module) => {
        const ownedFiles = files.filter((file) => (module.paths || []).some((sourcePath) => fileBelongsToPath(file, sourcePath)));
        const testCandidates = moduleTests(module, tests);
        const payload = {
            ...generation,
            module: module.module,
            paths: module.paths,
            source_files: ownedFiles.map(normalizedPath),
            symbols: indexSymbols(ownedFiles),
            tests: testCandidates,
        };
        writeJson(resolveMapPath(module, "generated.json"), payload);
        return {
            id: module.module,
            title: module.title,
            purpose: module.purpose,
            aliases: module.aliases,
            paths: module.paths,
            entry_points: module.entry_points,
            data_tables: module.data_tables,
            dependencies: module.dependencies,
            invariants: module.invariants,
            tests: module.tests,
            flows: module.flows.map((flow) => normalizedPath(resolveMapPath(module, flow))),
            source_file_count: ownedFiles.length,
            map: normalizedPath(module.path),
        };
    });

    writeJson(resolve(generatedRoot, "module-index.json"), { ...generation, modules: indexedModules });
    writeJson(resolve(generatedRoot, "dependency-graph.json"), {
        ...generation,
        nodes: indexedModules.map(({ id, title }) => ({ id, title })),
        edges: indexedModules.flatMap((module) => module.dependencies.map((to) => ({ from: module.id, to }))),
    });
    writeJson(resolve(generatedRoot, "database-index.json"), { ...generation, ...database });
    writeJson(resolve(generatedRoot, "routes-index.json"), { ...generation, routes });
    writeJson(resolve(generatedRoot, "symbols-index.json"), {
        ...generation,
        scope: "Global type navigation. Module generated.json files include functions/methods for their owned source anchors.",
        symbols: symbols.filter((symbol) => symbol.kind === "php_type" || symbol.kind === "python_class"),
    });
    writeJson(resolve(generatedRoot, "tests-index.json"), { ...generation, tests });
    writeJson(resolve(generatedRoot, "integrations-index.json"), { ...generation, ...integrations });
    writeJson(resolve(generatedRoot, "manifest.json"), {
        ...generation,
        indexes: generatedFiles.filter((file) => file !== "manifest.json"),
        module_indexes: indexedModules.map((module) => `.project-map/modules/${module.id}/generated.json`),
    });
    console.log(`Project Map generated (${files.length} source files, digest ${digest.slice(0, 12)}).`);
}

function check() {
    const errors = validateStructure({ requireGenerated: true });
    const manifestPath = resolve(generatedRoot, "manifest.json");
    if (existsSync(manifestPath)) {
        try {
            const manifest = JSON.parse(readText(manifestPath));
            const digest = sourceDigest();
            if (manifest.source_digest !== digest) {
                errors.push("Generated indexes are stale. Run: node scripts/project-map/project-map.mjs generate");
            }
            if (manifest.generator_digest !== fileDigest(resolve(scriptDirectory, "project-map.mjs"))) {
                errors.push("Generated indexes were built by an older generator. Run: node scripts/project-map/project-map.mjs generate");
            }
        } catch (error) {
            errors.push(`Cannot read generated manifest: ${error.message}`);
        }
    }
    if (errors.length) {
        console.error("Project Map check failed:\n- " + errors.join("\n- "));
        process.exitCode = 1;
        return;
    }
    console.log("Project Map check passed.");
}

function impact(base = process.argv[3] || "HEAD") {
    const changed = changedFiles(base);
    if (!changed.length) {
        console.log(`No added/copied/modified files relative to ${base}; no Map Impact Check is needed.`);
        return;
    }
    const moduleList = modules();
    const affected = new Map();
    const matchedPaths = new Set();
    for (const changedPath of changed) {
        for (const module of moduleList) {
            const isMappedSource = (module.paths || []).some((sourcePath) => changedPath === sourcePath || changedPath.startsWith(`${sourcePath}/`));
            const isMappedTest = (module.tests || []).includes(changedPath);
            if (isMappedSource || isMappedTest) {
                const paths = affected.get(module.module) || [];
                paths.push(changedPath);
                affected.set(module.module, paths);
                matchedPaths.add(changedPath);
            }
        }
    }
    const sourceChanged = changed.some((path) => /^(backend-laravel\/(app|routes|database\/migrations|tests)|ai-service-python\/(app|tests))\//.test(path));
    const unmappedSourcePaths = changed.filter((path) => /^(backend-laravel\/(app|routes|database\/migrations|tests)|ai-service-python\/(app|tests))\//.test(path) && !matchedPaths.has(path));
    const recommendations = new Set();
    if (sourceChanged) recommendations.add("Run generate: indexed source or tests changed.");
    if (changed.some((path) => path.startsWith("backend-laravel/database/migrations/"))) recommendations.add("Review the owning module's data/transition state and flow.");
    if (changed.some((path) => path.startsWith("backend-laravel/routes/") || path.includes("/Controllers/"))) recommendations.add("Review module entry points and any externally visible flow.");
    if (changed.some((path) => path.startsWith("ai-service-python/app/") || /ContractService\.php$/.test(path))) recommendations.add("Review Laravel/Python contract and flow if request, response, hash or ownership changed.");
    if (changed.some((path) => /StateMachine|Lifecycle|Status|Transition/.test(path))) recommendations.add("Review the affected states.md transition table.");
    if (changed.some((path) => /Services\//.test(path))) recommendations.add("Decide whether module ownership, dependency or entry point changed; update module.yaml only if it did.");

    console.log(`Project Map impact relative to ${base}:`);
    console.log(`- Changed files: ${changed.length}`);
    if (affected.size) {
        for (const [id, paths] of affected) console.log(`- Module '${id}': ${paths.length} mapped source path(s)`);
    }
    if (unmappedSourcePaths.length) {
        console.log(`- Unmapped source paths: ${unmappedSourcePaths.length}; inspect whether an existing module needs a new source anchor or a new module is required.`);
    }
    if (!affected.size && !unmappedSourcePaths.length) {
        console.log("- No changed source path matches a current module anchor; decide whether a module map needs a new path or module.");
    }
    console.log("Required human/agent decision:");
    for (const recommendation of recommendations.size ? recommendations : ["No indexed source changed; decide whether the changed documentation/operations behavior needs a map edit."]) {
        console.log(`- ${recommendation}`);
    }
    console.log("Then update only the affected semantic map files, run generate when requested, and finish with check.");
}

if (command === "generate") generate();
else if (command === "check") check();
else if (command === "impact") impact();
else {
    console.error("Usage: node scripts/project-map/project-map.mjs <impact [base]|generate|check>");
    process.exitCode = 1;
}
