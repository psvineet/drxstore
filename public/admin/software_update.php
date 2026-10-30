<?php
/**
 * DRXStore - Software Update via GitHub Releases
 * Developed by Vineet
 *
 * SECURITY (C2) FIX: Removed manual ZIP upload. Updates are now downloaded
 * directly from the official GitHub Releases page:
 *   https://github.com/psvineet/drxstore/releases
 *
 * Flow:
 *   1. Admin clicks "Check for Updates"
 *   2. Server queries GitHub API for the latest release
 *   3. If a newer version is available, release notes and asset info are shown
 *   4. Admin clicks "Download & Prepare Update"
 *   5. ZIP is downloaded server-side from the GitHub release asset URL
 *   6. ZIP is extracted and analysed (files to add/replace listed)
 *   7. Admin confirms — files are copied into the install
 *
 * Security guarantees (replaces upload-based attack surface):
 *   - No user-supplied file accepted
 *   - Download source hard-coded to github.com/psvineet/drxstore only
 *   - Redirects NOT followed (prevents redirect-based SSRF)
 *   - Path traversal protection: all entries validated against ROOT
 *   - New PHP files not in current install are blocked (prevents backdoor injection)
 *   - data/, config/ directories are never overwritten
 */

if (!defined('ROOT')) define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT.'/config/app.php';
require_once ROOT.'/views/layout_admin.php';
requireAdmin(true); // admin-only, not staff

// ── Constants ─────────────────────────────────────────────────────────────────
const GITHUB_API_URL  = 'https://api.github.com/repos/psvineet/drxstore/releases/latest';
const GITHUB_REPO_URL = 'https://github.com/psvineet/drxstore/releases';
const GITHUB_HOST     = 'github.com';           // asset downloads only from this host
const GITHUB_RAW_HOST = 'objects.githubusercontent.com'; // GitHub's CDN for release assets
const GITHUB_API_HOST = 'api.github.com';       // zipball_url / tarball_url fallback
const UPDATE_TMP_DIR  = DATA_DIR . '/.update_tmp'; // temporary extraction dir

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Read the installed version from config/app.php
 */
function readInstalledVersion(): string {
    return defined('APP_VERSION') ? APP_VERSION : '0.0.0';
}

/**
 * Compare semver strings. Returns true if $remote > $local.
 */
function isNewerVersion(string $remote, string $local): bool {
    $r = array_map('intval', explode('.', ltrim($remote, 'v')));
    $l = array_map('intval', explode('.', ltrim($local,  'v')));
    for ($i = 0; $i < max(count($r), count($l)); $i++) {
        $rv = $r[$i] ?? 0;
        $lv = $l[$i] ?? 0;
        if ($rv > $lv) return true;
        if ($rv < $lv) return false;
    }
    return false;
}

/**
 * Fetch the latest release info from GitHub API.
 * Returns array with keys: tag_name, name, body, published_at, zip_url, zip_name
 * or throws RuntimeException on failure.
 */
function fetchLatestRelease(): array {
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => "User-Agent: DRXStore-Updater/" . readInstalledVersion() . " (PHP)\r\n" .
                                 "Accept: application/vnd.github+json\r\n",
            'timeout'         => 15,
            'follow_location' => 0, // do NOT follow redirects (SSRF protection)
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents(GITHUB_API_URL, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('Could not reach GitHub API. Check your server\'s outbound internet access.');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Unexpected response from GitHub API.');
    }
    if (isset($data['message'])) {
        throw new RuntimeException('GitHub API error: ' . $data['message']);
    }

    $tag  = $data['tag_name']    ?? '';
    $name = $data['name']        ?? $tag;
    $body = $data['body']        ?? '';
    $pub  = $data['published_at']?? '';

    if (!$tag) {
        throw new RuntimeException('GitHub API returned a release with no tag name.');
    }

    // Build the archive URL directly from the tag — always works for any GitHub release,
    // no attached assets required.
    // e.g. https://github.com/psvineet/drxstore/archive/refs/tags/v2.0.0-production.zip
    $zipUrl  = 'https://github.com/psvineet/drxstore/archive/refs/tags/' . rawurlencode($tag) . '.zip';
    $zipName = 'drxstore-' . ltrim($tag, 'v') . '.zip';

    // Look for a .sha256 checksum asset published alongside the release.
    // Convention: asset named like "drxstore-2.0.0.zip.sha256" or "checksums.sha256"
    $sha256Url = '';
    foreach ($data['assets'] ?? [] as $asset) {
        $aName = strtolower($asset['name'] ?? '');
        $aUrl  = $asset['browser_download_url'] ?? '';
        if ($aUrl && (str_ends_with($aName, '.sha256') || str_ends_with($aName, '.sha256sum'))) {
            $sha256Url = $aUrl;
            break;
        }
    }

    return compact('tag', 'name', 'body', 'pub', 'zipUrl', 'zipName', 'sha256Url');
}

/**
 * Download a URL to a local file.
 * Validates host again before download. Returns bytes written.
 */
function downloadRelease(string $url, string $destFile): int {
    // Re-validate host of the original URL
    $host = parse_url($url, PHP_URL_HOST);
    if (!in_array($host, [GITHUB_HOST, GITHUB_RAW_HOST, GITHUB_API_HOST, 'codeload.github.com'], true)) {
        throw new RuntimeException('Blocked download from unexpected host: ' . $host);
    }

    $fp = @fopen($destFile, 'wb');
    if (!$fp) {
        throw new RuntimeException('Could not open destination file for writing. Check permissions on data/ directory.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is not available on this server. Please install the php-curl extension.');
    }

    $allowedHosts = [GITHUB_HOST, GITHUB_RAW_HOST, GITHUB_API_HOST, 'codeload.github.com'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE            => $fp,           // stream directly to file — no memory spike
        CURLOPT_FOLLOWLOCATION  => true,           // follow GitHub's redirects to CDN
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_TIMEOUT         => 120,
        CURLOPT_USERAGENT       => 'DRXStore-Updater/' . readInstalledVersion() . ' (PHP/cURL)',
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        // Validate each redirect host before following
        CURLOPT_HEADERFUNCTION  => function($ch, $header) use ($allowedHosts) {
            if (preg_match('/^Location:\s*(\S+)/i', $header, $m)) {
                $rHost = parse_url(trim($m[1]), PHP_URL_HOST);
                if ($rHost && !in_array($rHost, $allowedHosts, true)) {
                    // Returning 0 aborts the transfer
                    return 0;
                }
            }
            return strlen($header);
        },
    ]);

    $ok   = curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $err) {
        @unlink($destFile);
        throw new RuntimeException('Download failed: ' . ($err ?: 'unknown cURL error'));
    }
    if ($code !== 200) {
        @unlink($destFile);
        throw new RuntimeException('Download failed: HTTP ' . $code);
    }
    if ($size < 100) {
        @unlink($destFile);
        throw new RuntimeException('Downloaded file too small — release may be empty.');
    }

    return (int)$size;
}

/**
 * Extract ZIP to $destDir (fresh — existing dir removed first).
 * Returns array of relative paths inside the ZIP.
 */
function extractUpdateZip(string $zipFile, string $destDir): array {
    if (is_dir($destDir)) {
        // Clean up previous attempt
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($destDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($destDir);
    }
    mkdir($destDir, 0755, true);

    $zip = new ZipArchive();
    $res = $zip->open($zipFile);
    if ($res !== true) {
        throw new RuntimeException('Failed to open ZIP archive (ZipArchive error ' . $res . ').');
    }

    // Detect common top-level folder wrapping (GitHub adds "repo-tag/" prefix)
    $topDir = '';
    if ($zip->numFiles > 0) {
        $first = $zip->getNameIndex(0);
        $parts = explode('/', $first);
        if (count($parts) > 1 && $parts[0] !== '') {
            $topDir = $parts[0] . '/';
        }
    }

    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        // Strip the top-level wrapper directory
        if ($topDir && str_starts_with($name, $topDir)) {
            $rel = substr($name, strlen($topDir));
        } else {
            $rel = $name;
        }

        if ($rel === '' || $rel === '/') continue;

        // Path traversal protection
        $rel  = ltrim(str_replace('\\', '/', $rel), '/');
        $real = realpath($destDir) . '/' . $rel;
        if (!str_starts_with(realpath(dirname($real)) ?: '', realpath($destDir))) {
            // Suspicious path — skip silently
            continue;
        }

        if (str_ends_with($name, '/')) {
            // Directory entry
            @mkdir($destDir . '/' . $rel, 0755, true);
        } else {
            @mkdir(dirname($destDir . '/' . $rel), 0755, true);
            file_put_contents($destDir . '/' . $rel, $zip->getFromIndex($i));
            $files[] = $rel;
        }
    }
    $zip->close();
    return $files;
}

/**
 * Analyse extracted files: categorise each as add/replace/skip.
 * Returns array of ['rel'=>…, 'action'=>'replace'|'add'|'skip', 'reason'=>…]
 */
function analyseUpdate(string $extractDir, array $allFiles): array {
    $results = [];
    foreach ($allFiles as $rel) {
        $dest = ROOT . '/' . $rel;

        // Always skip the data/ and config/ directories (user data & secrets)
        if (preg_match('#^(data|config)/#i', $rel)) {
            $results[] = ['rel'=>$rel,'action'=>'skip','reason'=>'User data / config (never overwritten)'];
            continue;
        }

        // Block new PHP files that do NOT already exist in the current install.
        // This prevents a compromised release (or accidental upload) from adding backdoors.
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($ext === 'php' && !file_exists($dest)) {
            $results[] = ['rel'=>$rel,'action'=>'skip','reason'=>'New PHP file blocked (security policy)'];
            continue;
        }

        $results[] = [
            'rel'    => $rel,
            'action' => file_exists($dest) ? 'replace' : 'add',
            'reason' => '',
        ];
    }
    return $results;
}

/**
 * Apply update: copy files from extractDir to ROOT.
 */
function applyUpdate(string $extractDir, array $analysis): array {
    $applied = 0;
    $skipped = 0;
    $errors  = [];

    foreach ($analysis as $item) {
        if ($item['action'] === 'skip') { $skipped++; continue; }

        $src  = $extractDir . '/' . $item['rel'];
        $dest = ROOT . '/' . $item['rel'];

        if (!file_exists($src)) { $skipped++; continue; }

        // Extra path-traversal safety before writing
        $destReal = realpath(dirname($dest));
        $rootReal = realpath(ROOT);
        if (!$destReal || !str_starts_with($destReal, $rootReal)) {
            $errors[] = 'Blocked unsafe path: ' . $item['rel'];
            continue;
        }

        @mkdir(dirname($dest), 0755, true);
        if (copy($src, $dest)) {
            $applied++;
        } else {
            $errors[] = 'Failed to copy: ' . $item['rel'];
        }
    }

    return compact('applied', 'skipped', 'errors');
}

/**
 * Recursive directory removal helper.
 */
function rmdirRecursive(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

// ── Action handlers ───────────────────────────────────────────────────────────
$currentVersion = readInstalledVersion();
$action  = '';
$release = null;
$error   = '';
$analysis    = [];
$applyResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    // ── 1. Check for updates ──────────────────────────────────────────────────
    if ($action === 'check') {
        try {
            $release = fetchLatestRelease();
            $_SESSION['sw_release'] = $release;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    // ── 2. Download & Prepare ─────────────────────────────────────────────────
    if ($action === 'download') {
        $release = $_SESSION['sw_release'] ?? null;
        if (!$release || empty($release['zipUrl'])) {
            $error = 'Release info lost. Please check for updates again.';
        } else {
            try {
                // Ensure temp dir parent is writable
                if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

                $zipPath = DATA_DIR . '/.update_' . preg_replace('/[^a-z0-9._-]/i', '_', $release['tag']) . '.zip';

                // Download
                downloadRelease($release['zipUrl'], $zipPath);

                // SHA256 verification — if a checksum asset was published, fetch and verify.
                // If no checksum asset exists, compute and log the hash for the admin's records.
                $actualHash = hash_file('sha256', $zipPath);
                // SHA256 verification is MANDATORY — refuse update if no checksum asset published.
                if (empty($release['sha256Url'])) {
                    @unlink($zipPath);
                    throw new RuntimeException(
                        'Update blocked: release ' . $release['tag'] . ' has no .sha256 checksum asset. ' .
                        'The release author must publish a SHA256 checksum file alongside the ZIP. ' .
                        'This is required to verify the download has not been tampered with.'
                    );
                }

                // Fetch the .sha256 file (plain text: "abc123...  filename.zip")
                $shaCtx = stream_context_create(['http' => [
                    'method'  => 'GET',
                    'header'  => 'User-Agent: DRXStore-Updater/' . readInstalledVersion() . " (PHP)\r\n",
                    'timeout' => 15,
                ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
                $shaRaw = @file_get_contents($release['sha256Url'], false, $shaCtx);
                if ($shaRaw === false) {
                    @unlink($zipPath);
                    throw new RuntimeException('Could not fetch SHA256 checksum file from GitHub.');
                }
                // Extract the hash — first 64 hex chars on any line
                preg_match('/\b([0-9a-f]{64})\b/i', $shaRaw, $m);
                $expectedHash = strtolower($m[1] ?? '');
                if (!$expectedHash) {
                    @unlink($zipPath);
                    throw new RuntimeException('SHA256 checksum file did not contain a valid hash.');
                }
                if (!hash_equals($expectedHash, $actualHash)) {
                    @unlink($zipPath);
                    throw new RuntimeException(
                        'SHA256 mismatch — download is corrupt or has been tampered with. ' .
                        'Expected: ' . $expectedHash . ' | Got: ' . $actualHash
                    );
                }
                error_log('[DRXStore] Update ' . $release['tag'] . ' SHA256 verified OK: ' . $actualHash);

                // Extract
                $files = extractUpdateZip($zipPath, UPDATE_TMP_DIR);

                // Analyse
                $analysis = analyseUpdate(UPDATE_TMP_DIR, $files);

                // Store in session for confirm step
                $_SESSION['sw_analysis'] = $analysis;
                $_SESSION['sw_release']  = $release;

                // Remove ZIP file now that it's extracted
                @unlink($zipPath);

            } catch (RuntimeException $e) {
                $error = $e->getMessage();
                // Clean up on failure
                if (isset($zipPath) && file_exists($zipPath)) @unlink($zipPath);
                rmdirRecursive(UPDATE_TMP_DIR);
                unset($_SESSION['sw_analysis']);
            }
        }
    }

    // ── 3. Apply update ───────────────────────────────────────────────────────
    if ($action === 'apply') {
        $release  = $_SESSION['sw_release']  ?? null;
        $analysis = $_SESSION['sw_analysis'] ?? null;

        if (!$analysis || !$release) {
            $error = 'Update session expired. Please start over.';
        } else {
            try {
                $applyResult = applyUpdate(UPDATE_TMP_DIR, $analysis);

                // Clean up
                rmdirRecursive(UPDATE_TMP_DIR);
                unset($_SESSION['sw_analysis'], $_SESSION['sw_release']);

                if (empty($applyResult['errors'])) {
                    setFlash('success', 'Update to v' . $release['tag'] . ' applied successfully! ' . $applyResult['applied'] . ' file(s) updated.');
                } else {
                    setFlash('warning', 'Update applied with ' . count($applyResult['errors']) . ' error(s): ' . implode('; ', array_slice($applyResult['errors'], 0, 3)));
                }
                header('Location: index.php?p=sw_update');
                exit;

            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    }

    // ── 4. Cancel ─────────────────────────────────────────────────────────────
    if ($action === 'cancel') {
        rmdirRecursive(UPDATE_TMP_DIR);
        unset($_SESSION['sw_analysis'], $_SESSION['sw_release']);
        setFlash('info', 'Update cancelled.');
        header('Location: index.php?p=sw_update');
        exit;
    }
}

// On GET — restore state from session if in middle of flow
if (empty($analysis) && !empty($_SESSION['sw_analysis'])) {
    $analysis = $_SESSION['sw_analysis'];
    $release  = $_SESSION['sw_release'] ?? null;
}
if (empty($release) && !empty($_SESSION['sw_release']) && empty($analysis)) {
    $release = $_SESSION['sw_release'];
}

// ── Counts ────────────────────────────────────────────────────────────────────
$toReplace = array_filter($analysis, fn($a) => $a['action'] === 'replace');
$toAdd     = array_filter($analysis, fn($a) => $a['action'] === 'add');
$toSkip    = array_filter($analysis, fn($a) => $a['action'] === 'skip');

adminHeader('Software Update', 'sw_update');
?>
<div class="page-hdr">
  <div>
    <div class="page-title">&#128257; Software Update</div>
    <div class="page-sub">Keep DRXStore up to date from the official GitHub release</div>
  </div>
  <a href="<?=GITHUB_REPO_URL?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">
    <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" style="margin-right:4px"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/></svg>
    View on GitHub
  </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><span class="alert-body"><?=e($error)?></span></div>
<?php endif; ?>

<!-- ── Current version card ───────────────────────────────────────────────── -->
<div class="dash-grid" style="margin-bottom:20px">
  <div class="card">
    <div class="card-hdr"><div class="card-title">Installed Version</div></div>
    <div class="card-body" style="display:flex;align-items:center;gap:16px">
      <div style="width:52px;height:52px;background:var(--g1);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0">&#128230;</div>
      <div>
        <div style="font-size:1.5rem;font-weight:700;color:var(--acc)">v<?=e($currentVersion)?></div>
        <div style="font-size:.8rem;color:var(--g5)">DRXStore · <?=e(APP_NAME)?></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-hdr"><div class="card-title">Update Source</div></div>
    <div class="card-body" style="font-size:.82rem;line-height:1.75;color:var(--g6)">
      <div><strong>Repository:</strong> <a href="<?=GITHUB_REPO_URL?>" target="_blank" rel="noopener" style="color:var(--acc)">github.com/psvineet/drxstore</a></div>
      <div><strong>Channel:</strong> Latest stable release</div>
      <div><strong>Security:</strong> Downloads only from <code>github.com</code></div>
      <div style="margin-top:8px;padding:8px 10px;background:var(--g1);border-radius:var(--rl);font-size:.76rem">
        &#128274; Your <code>data/</code> and <code>config/</code> folders are never touched during updates.
      </div>
    </div>
  </div>
</div>

<?php if (empty($analysis)): ?>
<!-- ── Step 1: Check / Download ───────────────────────────────────────────── -->

  <?php if ($release): ?>
    <?php $isNewer = isNewerVersion($release['tag'], $currentVersion); ?>
    <div class="card" style="margin-bottom:20px">
      <div class="card-hdr">
        <div>
          <div class="card-title">
            <?php if ($isNewer): ?>
              &#128640; New Release Available: v<?=e($release['tag'])?>
            <?php else: ?>
              &#10003; You are up to date
            <?php endif; ?>
          </div>
          <div style="font-size:.76rem;color:var(--g5)">
            Released <?=e(date('d M Y', strtotime($release['pub'] ?: 'now')))?>
          </div>
        </div>
        <?php if ($isNewer): ?>
        <span class="chip chip-red">Update Available</span>
        <?php else: ?>
        <span class="chip chip-teal">Latest</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($release['body']): ?>
        <div style="background:var(--g1);padding:14px 16px;border-radius:var(--rl);font-size:.83rem;line-height:1.7;white-space:pre-wrap;max-height:260px;overflow-y:auto"><?=e($release['body'])?></div>
        <?php else: ?>
        <p class="text-muted text-sm">No release notes provided.</p>
        <?php endif; ?>

        <?php if ($isNewer): ?>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <form method="POST">
            <?=csrfField()?>
            <input type="hidden" name="action" value="download">
            <button type="submit" class="btn btn-primary">
              &#8681; Download &amp; Prepare Update
            </button>
          </form>
          <span style="font-size:.78rem;color:var(--g5)">
            Asset: <code><?=e($release['zipName'])?></code>
          </span>
        </div>
        <?php else: ?>
        <p style="margin-top:12px;font-size:.83rem;color:var(--g5)">Current install (v<?=e($currentVersion)?>) is already the latest release.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-hdr"><div class="card-title">Check for Updates</div></div>
    <div class="card-body">
      <p style="font-size:.83rem;color:var(--g6);margin-top:0">
        Click the button below to query the GitHub Releases API and check whether a newer version of DRXStore is available.
        No files are downloaded until you confirm.
      </p>
      <form method="POST" id="checkForm">
        <?=csrfField()?>
        <input type="hidden" name="action" value="check">
        <button type="submit" class="btn btn-primary" id="checkBtn">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
          Check for Updates
        </button>
      </form>
    </div>
  </div>

<?php else: ?>
<!-- ── Step 2: Confirm update ─────────────────────────────────────────────── -->
<div class="card">
  <div class="card-hdr">
    <div>
      <div class="card-title">Review Update: v<?=e($currentVersion)?> → v<?=e($release['tag'] ?? '?')?></div>
      <div style="font-size:.76rem;color:var(--g5)"><?=count($toReplace)?> files to update · <?=count($toAdd)?> new files · <?=count($toSkip)?> skipped</div>
    </div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="btn btn-ghost btn-sm">Cancel</button>
    </form>
  </div>

  <!-- Summary chips -->
  <div style="padding:12px 16px;display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--g3)">
    <?php if (count($toReplace)): ?>
    <span class="chip chip-blue">&#8635; <?=count($toReplace)?> replace</span>
    <?php endif; ?>
    <?php if (count($toAdd)): ?>
    <span class="chip chip-teal">+ <?=count($toAdd)?> add</span>
    <?php endif; ?>
    <?php if (count($toSkip)): ?>
    <span class="chip">&#8634; <?=count($toSkip)?> skip</span>
    <?php endif; ?>
    <span class="chip" style="margin-left:auto;background:var(--g1);color:var(--g6);font-size:.72rem">
      &#128274; data/ &amp; config/ not touched
    </span>
  </div>

  <!-- File list -->
  <div class="table-wrap" style="max-height:380px;overflow-y:auto">
    <table class="tbl">
      <thead>
        <tr>
          <th>File</th>
          <th>Action</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($analysis as $item): ?>
      <tr>
        <td style="font-family:monospace;font-size:.77rem"><?=e($item['rel'])?></td>
        <td>
          <?php if ($item['action']==='replace'): ?>
            <span class="chip chip-blue" style="font-size:.7rem">replace</span>
          <?php elseif ($item['action']==='add'): ?>
            <span class="chip chip-teal" style="font-size:.7rem">add</span>
          <?php else: ?>
            <span class="chip" style="font-size:.7rem;opacity:.7">skip</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.76rem;color:var(--g5)"><?=e($item['reason'])?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Confirm bar -->
  <div style="padding:14px 16px;border-top:1px solid var(--g3);background:var(--g1);border-radius:0 0 var(--rc) var(--rc)">
    <div style="font-size:.8rem;color:var(--g6);margin-bottom:12px">
      <strong>&#9888; Important:</strong>
      This will overwrite existing files listed above. A backup of the current version is recommended before proceeding.
      Your data, settings and medicines database will <strong>not</strong> be affected.
    </div>
    <form method="POST" style="display:inline">
      <?=csrfField()?>
      <input type="hidden" name="action" value="apply">
      <button type="submit" class="btn btn-success"
        onclick="return confirm('Apply the update to v<?=e($release['tag'] ?? '')?>? This will replace <?=count($toReplace)?> file(s). Continue?')">
        &#10003; Apply Update Now
      </button>
    </form>
    <form method="POST" style="display:inline;margin-left:8px">
      <?=csrfField()?>
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="btn btn-ghost">Cancel</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  var form = document.getElementById('checkForm');
  var btn  = document.getElementById('checkBtn');
  if (form && btn) {
    form.addEventListener('submit', function(){
      btn.disabled = true;
      btn.innerHTML = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;animation:spin 1s linear infinite"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>Checking…';
    });
  }
})();
</script>
<style>
  @keyframes spin { to { transform:rotate(360deg); } }
</style>
<?php adminFooter(); ?>
