const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const backend = path.resolve(__dirname, '..');
const shellDirectory = path.resolve(backend, '..', '.runtime', 'headless-shell');
const build = process.platform === 'win32'
  ? JSON.parse(fs.readFileSync(path.join(shellDirectory, 'build.json'), 'utf8').replace(/^\uFEFF/, '')) : {};
const env = { ...process.env, PHPRC: build.original_ini, COLUMNS: '120', LINES: '30',
  COMSPEC: path.join(shellDirectory, 'cmd.exe'), PATH: `${shellDirectory};${process.env.PATH}` };
const php = path.join(shellDirectory, 'php.exe');
const phpGui = path.join(path.dirname(php), 'php-win.exe');
const autoload = path.join(backend, 'vendor', 'autoload.php');

function run(code, args = [], directory = backend) {
  const output = fs.mkdtempSync(path.join(os.tmpdir(), 'hidden-shell-output-'));
  const stdoutPath = path.join(output, 'stdout.txt');
  const stderrPath = path.join(output, 'stderr.txt');
  const stdout = fs.openSync(stdoutPath, 'w');
  const stderr = fs.openSync(stderrPath, 'w');
  let result;
  try {
    // File-backed handles preserve legitimate background children without
    // waiting for those children to close an inherited Node output pipe.
    result = spawnSync(phpGui, ['-r', 'require $argv[1];\n' + code, autoload, ...args], {
      cwd: directory, env, shell: false, windowsHide: true, stdio: ['ignore', stdout, stderr], timeout: 10000,
    });
  } finally {
    fs.closeSync(stdout);
    fs.closeSync(stderr);
  }
  assert.equal(result.error, undefined);
  assert.equal(result.status, 0, fs.readFileSync(stderrPath, 'utf8'));
  return JSON.parse(fs.readFileSync(stdoutPath, 'utf8'));
}

test('actual windowless PHP -> Symfony shell -> Python preserves I/O and creates no console',
  { skip: process.platform !== 'win32' }, () => {
    const arguments = ['space and "quote"', '%PATH%', 'a&b|c^d!', '', 'Unicode: oʻzbek', 'trailing\\'];
    const code = [
      '$args = json_decode($argv[2], true);',
      '$python = $argv[3];',
      '$p = new Symfony\\Component\\Process\\Process(["python", "-B", "-c", $python, ...$args], getcwd(), null, "original input", 5);',
      '$p->run(); echo json_encode([$p->getExitCode(), $p->getOutput(), $p->getErrorOutput()]);',
    ].join('\n');
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'hidden shell test '));
    const python = 'import ctypes,json,sys; print(json.dumps([bool(ctypes.windll.kernel32.GetConsoleWindow()),sys.argv[1:],sys.stdin.read()])); sys.stderr.write("distinct error\\n"); sys.exit(7)';
    const [status, stdout, stderr] = run(code, [JSON.stringify(arguments), python], directory);
    assert.equal(status, 7);
    assert.deepEqual(JSON.parse(stdout), [false, arguments, 'original input']);
    assert.equal(stderr.replaceAll('\r\n', '\n'), 'distinct error\n');
  });

test('the shell retains a legitimate exit 124', { skip: process.platform !== 'win32' }, () => {
  const [status] = run('$p = Symfony\\Component\\Process\\Process::fromShellCommandline("exit /b 124"); $p->run(); echo json_encode([$p->getExitCode()]);');
  assert.equal(status, 124);
});

test('PHP shell_exec PID/helper probes also create no console', { skip: process.platform !== 'win32' }, () => {
  const code = [
    'echo json_encode(trim(shell_exec($argv[2])));',
  ].join('\n');
  const command = 'python -B -c "import ctypes,sys; print(bool(ctypes.windll.kernel32.GetConsoleWindow())); sys.exit(7)"';
  assert.equal(run(code, [command]), 'False');
});

test('PHP implicit proc_open also reaches the private shell', { skip: process.platform !== 'win32' }, () => {
  const code = [
    '$p = proc_open($argv[2], [0 => ["file", "NUL", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);',
    '$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);',
    'fclose($pipes[1]); fclose($pipes[2]); echo json_encode([proc_close($p), trim($out), $err]);',
  ].join('\n');
  const command = 'python -B -c "import ctypes,sys; print(bool(ctypes.windll.kernel32.GetConsoleWindow())); sys.exit(7)"';
  assert.deepEqual(run(code, [command]), [7, 'False', '']);
});

test('a Symfony timeout terminates the native shell helper tree', { skip: process.platform !== 'win32' }, () => {
  const code = [
    '$python = "import json,os,time; print(json.dumps(os.getpid()), flush=True); time.sleep(30)";',
    '$p = new Symfony\\Component\\Process\\Process(["python", "-B", "-u", "-c", $python], null, null, null, 1);',
    '$expired = false; try { $p->run(); } catch (Symfony\\Component\\Process\\Exception\\ProcessTimedOutException $e) { $expired = true; }',
    '$pid = trim($p->getOutput());',
    '$check = new Symfony\\Component\\Process\\Process(["python", "-B", "-c", "import ctypes,sys; k=ctypes.windll.kernel32; h=k.OpenProcess(0x1000,False,int(sys.argv[1])); print(bool(h)); h and k.CloseHandle(h)", $pid], null, null, null, 3);',
    '$check->mustRun(); echo json_encode([$expired, trim($check->getOutput())]);',
  ].join('\n');
  assert.deepEqual(run(code), [true, 'False']);
});

test('a normally completed shell preserves an explicitly started background child',
  { skip: process.platform !== 'win32' }, async () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'hidden-shell-background-'));
    const script = path.join(directory, 'child.py');
    const pidFile = path.join(directory, 'pid.txt');
    fs.writeFileSync(script, 'import os,sys,time\nopen(sys.argv[1], "w").write(str(os.getpid()))\ntime.sleep(30)\n');
    let pid;
    try {
      const command = `start /B "" python -B "${script}" "${pidFile}" >NUL 2>NUL`;
      assert.deepEqual(run('$p = Symfony\\Component\\Process\\Process::fromShellCommandline($argv[2]); $p->run(); echo json_encode([$p->getExitCode()]);', [command]), [0]);
      for (let attempt = 0; attempt < 50 && !fs.existsSync(pidFile); attempt++) {
        await new Promise(resolve => setTimeout(resolve, 100));
      }
      pid = Number(fs.readFileSync(pidFile, 'utf8'));
      assert.ok(Number.isSafeInteger(pid) && pid > 0);
      const code = '$p = new Symfony\\Component\\Process\\Process(["python", "-B", "-c", "import ctypes,sys; k=ctypes.windll.kernel32; h=k.OpenProcess(0x1000,False,int(sys.argv[1])); print(bool(h)); h and k.CloseHandle(h)", $argv[2]]); $p->mustRun(); echo json_encode(trim($p->getOutput()));';
      assert.equal(run(code, [String(pid)]), 'True');
    } finally {
      // This PID comes only from the uniquely-created test fixture above.
      if (Number.isSafeInteger(pid) && pid > 0) spawnSync(path.join(process.env.SystemRoot, 'System32', 'taskkill.exe'),
        ['/F', '/PID', String(pid)], { windowsHide: true, shell: false, stdio: 'ignore' });
    }
  });
