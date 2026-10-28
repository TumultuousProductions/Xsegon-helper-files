<?php
/**
 * Xsegon's Mac check, run by .github/workflows/mac-check.yml on GitHub's Mac machine with the
 * download's own PHP: the server is asked what the page would ask, and what it did is read back.
 *
 *     runtime/php/php .github/mac-check.php <the download's Xsegon folder>
 *
 * Every line says what was checked and what came. It ends 1 when anything failed.
 */
declare(strict_types=1);

$root = rtrim($argv[1] ?? '', '/');
$base = 'http://127.0.0.1:8099';
$failed = 0;

function call(string $method, string $path, ?array $body = null): array
{
    global $base;
    $c = curl_init($base . '/' . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CUSTOMREQUEST => $method,
                           CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . $base]]);
    if ($body !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = (string) curl_exec($c);
    $a = json_decode($raw, true);
    return is_array($a) ? $a : ['ok' => false, 'raw' => substr($raw, 0, 400)];
}

function check(string $what, bool $ok, string $saw = ''): void
{
    global $failed;
    echo ($ok ? 'ok    ' : 'FAIL  ') . $what . ($saw !== '' ? '  [' . $saw . ']' : '') . "\n";
    if (!$ok) {
        $failed++;
    }
}

/** A setup job, watched until it ends: what it said last. */
function job_until_done(int $minutes): array
{
    $end = time() + $minutes * 60;
    $last = '';
    do {
        sleep(5);
        $j = call('GET', 'api/setup.php?job=1');
        $job = $j['job'] ?? [];
        $now = ($job['kind'] ?? '?') . ' ' . ($job['state'] ?? '?') . ' ' . ($job['step'] ?? '');
        if ($now !== $last) {
            echo '      ' . date('H:i:s') . ' ' . $now . "\n";
            $last = $now;
        }
    } while (($job['state'] ?? '') === 'running' && time() < $end);
    return $job;
}

// 1. It answers, as the Mac download, on macOS.
$s = call('GET', 'api/status.php');
check('the server answers', ($s['ok'] ?? false) === true);
check('it runs on macOS', str_contains((string) ($s['build']['os'] ?? ''), 'Darwin'), (string) ($s['build']['os'] ?? ''));
check('it is the Mac download', str_contains((string) ($s['build']['package'] ?? ''), 'mac'), (string) ($s['build']['package'] ?? ''));
check('on PHP 8.5.12', ($s['build']['php'] ?? '') === '8.5.12', (string) ($s['build']['php'] ?? ''));
// xattr -p prints the mark's value, and nothing (its complaint goes to stderr) once it is gone.
check('the download mark came off its own programs',
    trim((string) shell_exec('xattr -p com.apple.quarantine ' . escapeshellarg($root . '/runtime/php/php') . ' 2>/dev/null')) === '');

// 2. The switch is there, and only on a Mac.
$set = call('GET', 'api/settings.php');
check('Settings > Launching has RetroArch to use', ($set['defaults']['launch']['macRetroArch'] ?? null) === 'own',
    json_encode($set['schema']['launch']['macRetroArch']['options'] ?? null));

// 3. A scan finds games, and they are kept (two stand-in files: names only, no game in them).
@mkdir($root . '/Games/snes', 0777, true);
@mkdir($root . '/Games/nes', 0777, true);
file_put_contents($root . '/Games/snes/Super Mario World (USA).sfc', str_repeat("\0", 524288));
file_put_contents($root . '/Games/nes/Metroid (USA).nes', str_repeat("\0", 131088));
$scan = call('GET', 'api/scan.php');
$new = $scan['new'] ?? [];
check('the scan finds the two', count($new) === 2, implode(', ', array_map(static fn ($g) => ($g['title'] ?? '?') . ' / ' . ($g['platform'] ?? '?'), $new)));
$acc = call('POST', 'api/scan.php', ['action' => 'accept', 'games' => $new]);
check('and they are kept', ($acc['ok'] ?? false) === true, 'accepted ' . ($acc['accepted'] ?? '?'));

// 4. RetroArch, for real: libretro's Mac disk image downloaded, checked, opened and put in place.
$g = call('POST', 'api/setup.php', ['get' => 'retroarch']);
check('Get RetroArch starts', ($g['ok'] ?? false) === true, (string) ($g['error'] ?? ''));
$job = job_until_done(25);
check('RetroArch is in place', ($job['state'] ?? '') === 'done', ($job['error'] ?? '') . ' ' . ($job['detail'] ?? ''));
echo '      opened with: ' . ($job['unpackedWith'] ?? '?') . ', info and pad files taken: ' . ($job['assetsTaken'] ?? '?') . "\n";
echo '      --version said: ' . substr((string) ($job['versionSaid'] ?? ''), 0, 300) . "\n";
check('it answers its version', (bool) preg_match('/Version:\s*1\.22\.2/', (string) ($job['versionSaid'] ?? '')));
call('GET', 'api/setup.php');            // the finished job is applied: retroarch.cfg and the emulator table
$ra = $root . '/data/emulators/retroarch';
check('the app is inside Xsegon\'s folder', is_file($ra . '/RetroArch.app/Contents/MacOS/RetroArch'));
check('its core info files came out', count(glob($ra . '/info/*.info') ?: []) > 100, (string) count(glob($ra . '/info/*.info') ?: []));
check('its controller profiles came out', is_dir($ra . '/autoconfig'), implode(' ', array_map('basename', glob($ra . '/autoconfig/*') ?: [])));
$cfg = (string) @file_get_contents($ra . '/retroarch.cfg');
check('retroarch.cfg names Xsegon\'s own folders', str_contains($cfg, 'libretro_directory = "' . $ra . '/cores"')
    && str_contains($cfg, 'savestate_directory = "' . $ra . '/states"'));

// 5. A core, the Mac kind.
$c = call('POST', 'api/setup.php', ['cores' => ['Super Nintendo Entertainment System', 'Nintendo Entertainment System']]);
check('the cores start', ($c['ok'] ?? false) === true, (string) ($c['error'] ?? ''));
$job = job_until_done(10);
$dylibs = array_map('basename', glob($ra . '/cores/*_libretro.dylib') ?: []);
check('the cores came, as .dylib', count($dylibs) >= 1, implode(', ', $dylibs));
$emu = json_decode((string) @file_get_contents($root . '/data/emulators.json'), true);
$snes = $emu['platforms']['super nintendo entertainment system']['options'][0]['args'] ?? '';
check('the Super Nintendo plays through it', str_contains((string) $snes, '_libretro.dylib'), (string) $snes);

// 6. RetroArch itself, through the start script, loading that core for its information.
$core = $ra . '/cores/' . ($dylibs[0] ?? 'none');
$out = shell_exec(escapeshellarg($ra . '/retroarch') . ' --features 2>&1 | head -5');
echo '      retroarch --features: ' . trim(preg_replace('/\s+/', ' ', (string) $out)) . "\n";

echo "\n" . ($failed === 0 ? 'everything checked came out right' : $failed . ' check(s) failed') . "\n";
exit($failed === 0 ? 0 : 1);
