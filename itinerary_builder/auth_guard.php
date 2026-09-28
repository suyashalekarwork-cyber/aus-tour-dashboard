<?php
// itinerary_builder/auth_guard.php — single access gate for the Itinerary Builder.
// Same mechanism as product-management/auth_guard.php: access requires BOTH
// (1) a main-dashboard session ($_SESSION['user_name']), and
// (2) a passed TOTP 2FA check this session ($_SESSION['analytics_2fa_ok']).
// The 2FA flag is shared with product-management on purpose — one code entry
// per dashboard session unlocks both tools (same per-user TOTP secret).
// PAGE mode (default): on failure redirect to login or render the 2FA form, then exit.
// API mode: define('GUARD_API', true) before the require → JSON error + exit, never HTML.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── TESTING ONLY: set to true to skip login + 2FA. NEVER deploy with true. ──
$BYPASS_AUTH_FOR_TESTING = false;
if ($BYPASS_AUTH_FOR_TESTING) {
    $_SESSION['user_name'] = $_SESSION['user_name'] ?? 'test_user';
    $IS_ADMIN = true;
    return;
}

require_once __DIR__ . '/../2fa/totp_lib.php';

$__guard_api = defined('GUARD_API') && GUARD_API;

// Step 1: must be logged into the main dashboard.
if (empty($_SESSION['user_name'])) {
    if ($__guard_api) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not authenticated. Log in to the dashboard first.']);
    } else {
        header('Location: ../login.php');
    }
    exit;
}

// 2FA logout (page mode helper).
if (!$__guard_api && isset($_GET['twofa_logout'])) {
    unset($_SESSION['analytics_2fa_ok']);
    header('Location: index.php');
    exit;
}

// Step 2: already verified this session → allow through.
if (!empty($_SESSION['analytics_2fa_ok'])) {
    $IS_ADMIN = true;   // exposed to the including page
    return;             // control returns to the caller
}

// Not verified yet.
if ($__guard_api) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Two-factor verification required. Open the itinerary builder page and verify first.']);
    exit;
}

// Page mode: verify a submitted code, else render the form.
require_once __DIR__ . '/../dbconn.php';

$twofaError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['analytics_2fa_code'])) {
    $res = twofa_verify_for_user($conn, (string) $_SESSION['user_name'], (string) $_POST['analytics_2fa_code']);
    require_once __DIR__ . '/../product-management/activity_log.php';
    if ($res['status'] === 'VALID') {
        $_SESSION['analytics_2fa_ok'] = true;
        log_action($conn, '2fa', '2fa verify success (itinerary builder)');
        // Reload the originally-requested URL (preserves any query string).
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    log_action($conn, '2fa', '2fa verify failed (itinerary builder): ' . (string) $res['status']);
    $twofaError = $res['detail'];
}

$selfAction = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
$who        = htmlspecialchars((string) $_SESSION['user_name'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Turtle Down Under 2FA</title>
    <link rel="icon" type="image/png" href="../product-management/asset/logo.png">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        body {
            font-family: "Segoe UI", sans-serif;
            background: #f4f5f7;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px
        }

        .card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 32px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, .07)
        }

        .logo {
            text-align: center;
            margin-bottom: 16px
        }

        .logo img {
            width: 100%;
            height: auto
        }

        p {
            font-size: 14px;
            color: #555;
            margin-bottom: 20px
        }

        .who {
            font-size: 12px;
            color: #888;
            margin-bottom: 18px
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #444;
            margin-bottom: 6px
        }

        input[type=text] {
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 12px 14px;
            font-size: 22px;
            letter-spacing: 6px;
            text-align: center;
            margin-bottom: 16px
        }

        input[type=text]:focus {
            outline: none;
            border-color: #378ADD
        }

        button {
            width: 100%;
            background: #378ADD;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 11px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer
        }

        button:hover {
            background: #185FA5
        }

        .err {
            background: #fdecea;
            color: #c62828;
            border: 1px solid #f5c6cb;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 13px;
            margin-bottom: 16px
        }

        .foot {
            margin-top: 16px;
            font-size: 12px;
            text-align: center
        }

        .foot a {
            color: #888;
            text-decoration: none
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="logo"><img src="../product-management/asset/logo.png" alt="Turtle Down Under"></div>
        <p>Enter the 6-digit code from your Authenticator app to continue.</p>
        <div class="who">Signed in as <strong><?= $who ?></strong></div>
        <?php if ($twofaError !== ''): ?><div class="err"><?= htmlspecialchars($twofaError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post" action="<?= $selfAction ?>">
            <label for="code">Authentication code</label>
            <input id="code" type="text" name="analytics_2fa_code" inputmode="numeric" pattern="\d{6}"
                maxlength="6" placeholder="XXXXXX" autocomplete="one-time-code" autofocus required>
            <button type="submit">Verify</button>
        </form>
        <div class="foot"><a href="../login.php">Not you? Return to dashboard login</a></div>
    </div>
</body>

</html>
<?php
exit;
