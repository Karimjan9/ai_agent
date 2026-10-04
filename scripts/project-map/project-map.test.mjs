import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { mkdtempSync, readFileSync, rmSync, rmdirSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { fileDigest, indexSymbols, readText, sourceDigest } from "./project-map.mjs";

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

function withTextFixtures(run) {
    const directory = mkdtempSync(join(tmpdir(), "project-map-eol-"));
    const paths = [join(directory, "fixture.py"), join(directory, "fixture.php")];
    try {
        run(paths);
    } finally {
        for (const path of paths) rmSync(path, { force: true });
        rmdirSync(directory);
    }
}

test("navigation digests and reads agree for LF, CRLF and mixed checkout text", () => {
    withTextFixtures(([python, php]) => {
        const pythonText = "# UTC fixture μ\n\ndef example():\n    pass\n";
        const phpText = "<?php\nclass Example {}\n";
        writeFileSync(python, pythonText);
        writeFileSync(php, phpText);
        const lfSourceDigest = sourceDigest([python, php]);
        const lfFileDigest = fileDigest(python);
        const rawLfDigest = createHash("sha256").update(readFileSync(python)).digest("hex");

        writeFileSync(python, pythonText.replace(/\n/g, "\r\n"));
        const rawCrlfDigest = createHash("sha256").update(readFileSync(python)).digest("hex");
        assert.notEqual(rawLfDigest, rawCrlfDigest);
        assert.equal(readText(python), pythonText);
        assert.equal(fileDigest(python), lfFileDigest);
        assert.equal(sourceDigest([python, php]), lfSourceDigest);

        writeFileSync(php, phpText.replace(/\n/g, "\r\n"));
        assert.equal(readText(php), phpText);
        assert.equal(sourceDigest([python, php]), lfSourceDigest);
    });
});

test("Python anchors identify the actual class and def rows after blank lines in either EOL", () => {
    withTextFixtures(([python]) => {
        const text = ["# header", "", "", "class Example:", "", "    async def method(self):",
            "        pass", "", "", "def top():", "    pass", ""].join("\n");
        const expected = [
            { symbol: "Example", kind: "python_class", line: 4 },
            { symbol: "method", kind: "python_function", line: 6 },
            { symbol: "top", kind: "python_function", line: 10 },
        ];
        for (const content of [text, text.replace(/\n/g, "\r\n")]) {
            writeFileSync(python, content);
            const symbols = indexSymbols([python]).map(({ symbol, kind, line }) => ({ symbol, kind, line }))
                .sort((left, right) => left.line - right.line);
            assert.deepEqual(symbols, expected);
        }
    });
});

test("navigation hash normalization preserves BOM and non-EOL encoding bytes", () => {
    withTextFixtures(([path]) => {
        const lf = Buffer.from([0xef, 0xbb, 0xbf, 0xff, 10, 0xc3, 0x28]);
        const crlf = Buffer.from([0xef, 0xbb, 0xbf, 0xff, 13, 10, 0xc3, 0x28]);
        writeFileSync(path, lf);
        const expectedFileDigest = createHash("sha256").update(lf).digest("hex");
        const expectedSourceDigest = sourceDigest([path]);
        assert.equal(fileDigest(path), expectedFileDigest);
        writeFileSync(path, crlf);
        assert.equal(fileDigest(path), expectedFileDigest);
        assert.equal(sourceDigest([path]), expectedSourceDigest);
        crlf[3] = 0xfe;
        writeFileSync(path, crlf);
        assert.notEqual(fileDigest(path), expectedFileDigest);
    });
});
