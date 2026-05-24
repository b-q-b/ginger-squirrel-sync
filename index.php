<?php
require_once __DIR__ . '/config/auth.php';

if (isLoggedIn()) {
    header('Location: /jupiter/ginger-sync/pages/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (login($password)) {
        header('Location: /jupiter/ginger-sync/pages/dashboard.php');
        exit;
    }
    $error = 'Incorrect password';
    usleep(500000);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ginger Sync — Sign in</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐿️</text></svg>">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #1a0f08 0%, #2d1810 50%, #1a0f08 100%);
            color: #f5e6d3;
        }
        .card {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 180, 120, 0.15);
            border-radius: 16px;
            padding: 48px 40px;
            width: 360px;
            backdrop-filter: blur(20px);
            box-shadow: 0 25px 60px rgba(0,0,0,0.4);
        }
        .brand {
            text-align: center;
            margin-bottom: 32px;
        }
        .brand .emoji { font-size: 48px; display: block; margin-bottom: 12px; }
        .brand h1 {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: 1px;
            color: #f5e6d3;
        }
        .brand p {
            font-size: 12px;
            color: rgba(245, 230, 211, 0.5);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 6px;
        }
        input[type="password"] {
            width: 100%;
            padding: 14px 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 180, 120, 0.15);
            border-radius: 10px;
            color: #f5e6d3;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s;
        }
        input[type="password"]:focus {
            border-color: rgba(255, 180, 120, 0.5);
        }
        button {
            width: 100%;
            margin-top: 16px;
            padding: 14px;
            background: linear-gradient(135deg, #d97a3a, #b8622a);
            border: 0;
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.2s;
        }
        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(217, 122, 58, 0.3);
        }
        .error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 10px;
            border-radius: 8px;
            font-size: 13px;
            text-align: center;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <span class="emoji">🐿️</span>
            <h1>Ginger Sync</h1>
            <p>ClickUp ↔ Trello</p>
        </div>
        <form method="POST">
            <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <input type="password" name="password" placeholder="Password" autofocus autocomplete="current-password" required>
            <button type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
