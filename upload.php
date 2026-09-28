<?php

/**
 * upload.php — browser upload page for the data team.
 *
 * Uploads the JSON exports into imports/ and runs import.php, without FTP or
 * File Manager. Files are sent in 1 MB chunks so the server's PHP upload
 * limits (upload_max_filesize / post_max_size) never matter, even for the
 * ~19 MB data.json. The server appends chunks to <name>.part and renames to
 * the real name when the last chunk arrives.
 *
 * Every request requires the 'import_key' from config.php.
 *
 * Open:  https://yourdomain/upload.php
 */

$config     = require __DIR__ . '/config.php';
$importsDir = __DIR__ . '/imports';

// accepted file names → first character the content must start with
$ALLOWED = [
    'themes.json'    => '[',
    'data.json'      => '{',
    'templates.json' => '[',
    'market.json'    => '[',
    'tokens.json'    => '[',
];

// ── chunk receiver (called by the page's own JavaScript) ──────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $reply = function (int $code, array $data) {
        http_response_code($code);
        echo json_encode($data);
        exit;
    };

    $key = $_POST['key'] ?? '';
    if (
        empty($config['import_key']) || $config['import_key'] === 'CHANGE_ME_TOO'
        || !hash_equals($config['import_key'], $key)
    ) {
        $reply(403, ['error' => 'Wrong import key.']);
    }

    $name = $_POST['name'] ?? '';
    if (!isset($ALLOWED[$name])) {
        $reply(400, ['error' => "$name: not an accepted file name"]);
    }
    if (($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $reply(400, ['error' => "$name: chunk upload failed (PHP error "
            . ($_FILES['chunk']['error'] ?? 'none') . ')']);
    }

    $offset = (int) ($_POST['offset'] ?? 0);
    $isLast = ($_POST['last'] ?? '') === '1';
    $part   = "$importsDir/$name.part";

    // offset 0 starts a fresh .part; later chunks must line up exactly with
    // what has been written so far (catches lost chunks and stale retries)
    if ($offset !== 0 && (!is_file($part) || filesize($part) !== $offset)) {
        @unlink($part);
        $reply(409, ['error' => "$name: chunk out of sequence — please retry the upload"]);
    }
    $bytes = file_get_contents($_FILES['chunk']['tmp_name']);
    if (file_put_contents($part, $bytes, $offset === 0 ? 0 : FILE_APPEND) === false) {
        $reply(500, ['error' => "$name: could not write into imports/ (folder not writable?)"]);
    }

    if (!$isLast) {
        $reply(200, ['ok' => true]);
    }

    // last chunk: sanity-check and move into place
    $fh   = fopen($part, 'r');
    $head = ltrim((string) fread($fh, 64));
    fclose($fh);
    if ($head === '' || $head[0] !== $ALLOWED[$name]) {
        @unlink($part);
        $reply(400, ['error' => "$name: doesn't look right — expected JSON starting with '{$ALLOWED[$name]}'"]);
    }
    if (!rename($part, "$importsDir/$name")) {
        $reply(500, ['error' => "$name: could not move the finished file into place"]);
    }
    $reply(200, ['ok' => true, 'done' => true]);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TDU Itinerary Builder — Data Upload</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            background: #f5f5f4;
            color: #292524;
            max-width: 640px;
            margin: 40px auto;
            padding: 0 16px;
        }

        .card {
            background: #fff;
            border: 1px solid #e7e5e4;
            border-radius: 8px;
            padding: 24px;
        }

        h1 {
            font-size: 20px;
            margin: 0 0 4px;
        }

        p.sub {
            color: #78716c;
            margin-top: 0;
        }

        label {
            display: block;
            font-weight: 600;
            margin: 16px 0 4px;
        }

        input[type=password],
        input[type=file] {
            width: 100%;
            box-sizing: border-box;
            padding: 8px;
            border: 1px solid #d6d3d1;
            border-radius: 6px;
            background: #fafaf9;
        }

        .check {
            margin: 14px 0;
        }

        button {
            background: #185FA5;
            color: #fff;
            border: 0;
            border-radius: 6px;
            padding: 10px 20px;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover {
            background: #134b84;
        }

        button:disabled {
            background: #a8a29e;
            cursor: default;
        }

        .msg {
            border-radius: 6px;
            padding: 10px 14px;
            margin: 10px 0;
            font-size: 14px;
        }

        .err {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
        }

        .ok {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #15803d;
        }

        .files {
            font-size: 13px;
            color: #78716c;
            margin-top: 18px;
            line-height: 1.6;
        }

        code {
            background: #f5f5f4;
            padding: 1px 5px;
            border-radius: 4px;
        }

        #progress {
            margin-top: 12px;
            font-size: 14px;
        }

        .bar {
            background: #e7e5e4;
            border-radius: 4px;
            height: 8px;
            margin: 4px 0 10px;
            overflow: hidden;
        }

        .bar>div {
            background: #185FA5;
            height: 100%;
            width: 0;
            transition: width .15s;
        }

        pre#log {
            background: #1c1917;
            color: #e7e5e4;
            border-radius: 6px;
            padding: 14px;
            font-size: 12.5px;
            overflow-x: auto;
            white-space: pre-wrap;
            display: none;
        }
    </style>
</head>

<body>
    <div class="card">
        <h1>Data Upload</h1>
        <p class="sub">Upload new JSON exports and load them into the database.
            Files are sent in small chunks, so size limits don't apply.</p>

        <form id="form">
            <label for="key">Import key</label>
            <input type="password" id="key" required autocomplete="off">

            <label for="files">JSON files (any subset)</label>
            <input type="file" id="files" multiple accept=".json,application/json" required>

            <div class="check">
                <label style="font-weight:400; display:inline">
                    <input type="checkbox" id="run" checked>
                    Run the import right after uploading
                </label>
            </div>

            <button type="submit" id="go">Upload</button>
        </form>

        <div id="progress"></div>
        <pre id="log"></pre>

        <div class="files">
            Accepted files: <code>themes.json</code> <code>data.json</code>
            <code>templates.json</code> <code>market.json</code> <code>tokens.json</code><br>
            themes + data + templates belong together — deliver all three when any of
            them changes. market and tokens are independent. Format details:
            <code>imports/README.md</code>.
        </div>
    </div>

    <script>
        const CHUNK = 1024 * 1024; // 1 MB — safely under any PHP post limit
        const $ = id => document.getElementById(id);

        function note(kind, text) {
            const d = document.createElement('div');
            d.className = 'msg ' + kind;
            d.textContent = text;
            $('progress').appendChild(d);
        }

        async function uploadFile(file, key) {
            const row = document.createElement('div');
            row.textContent = file.name;
            const bar = document.createElement('div');
            bar.className = 'bar';
            bar.innerHTML = '<div></div>';
            $('progress').append(row, bar);

            for (let offset = 0; offset < file.size; offset += CHUNK) {
                const fd = new FormData();
                fd.append('key', key);
                fd.append('name', file.name);
                fd.append('offset', offset);
                fd.append('last', offset + CHUNK >= file.size ? '1' : '0');
                fd.append('chunk', file.slice(offset, offset + CHUNK));
                const res = await fetch(location.href, {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json().catch(() => ({
                    error: 'server returned a non-JSON response'
                }));
                if (!res.ok || data.error) throw new Error(data.error || ('HTTP ' + res.status));
                bar.firstChild.style.width = Math.min(100, Math.round((offset + CHUNK) / file.size * 100)) + '%';
            }
            bar.firstChild.style.width = '100%';
        }

        $('form').addEventListener('submit', async e => {
            e.preventDefault();
            const key = $('key').value;
            const files = [...$('files').files];
            $('progress').innerHTML = '';
            $('log').style.display = 'none';
            $('go').disabled = true;

            try {
                for (const f of files) await uploadFile(f, key);
                note('ok', 'Uploaded: ' + files.map(f => f.name).join(', '));

                if ($('run').checked) {
                    note('ok', 'Running import…');
                    const res = await fetch('import.php?key=' + encodeURIComponent(key));
                    $('log').textContent = await res.text();
                    $('log').style.display = 'block';
                }
            } catch (err) {
                note('err', err.message);
            } finally {
                $('go').disabled = false;
            }
        });
    </script>
</body>

</html>