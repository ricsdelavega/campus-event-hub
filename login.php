<?php
session_start();

require __DIR__ . '/config/database.php';

if (!empty($_SESSION['user_id'])) {
    if (($_SESSION['user_role'] ?? 'student') === 'admin') {
        header('Location: admin/index.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$email = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (
            !is_string($email) ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !is_string($password)
        ) {
            $error = 'Invalid email or password.';
        } else {
            $sql = "SELECT id, full_name, email,
password_hash, role
FROM users
WHERE email = :email
LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['email' => $email]);

            $user = $stmt->fetch();

            if (
                $user &&
                password_verify($password, $user['password_hash'])
            ) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_role'] = $user['role'];

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $redirectTo = $_POST['redirect_to'] ?? '';

                if (
                    is_string($redirectTo) &&
                    $redirectTo !== '' &&
                    $redirectTo[0] !== '/' &&
                    strpos($redirectTo, '//') === false &&
                    strpos($redirectTo, ':') === false
                ) {
                    header('Location: ' . $redirectTo);
                } elseif ($user['role'] === 'admin') {
                    header('Location: admin/index.php');
                } else {
                    header('Location: index.php');
                }

                exit;
            }

            $error = 'Invalid email or password.';
        }
    }
}

function escape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login | Pateros Technological College</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body.auth-page {
            background:
                radial-gradient(circle at 82% 14%, rgba(249, 176, 0, 0.13), transparent 42%),
                radial-gradient(circle at 15% 85%, rgba(39, 163, 119, 0.20), transparent 45%),
                linear-gradient(160deg, #f2fbf6 0%, #e6f4ec 55%, #dcefe4 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .auth-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 18px;
        }

        .auth-card {
            width: 100%;
            max-width: 1180px;
            background: white;
            border-radius: 22px;
            overflow: hidden;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            box-shadow: 0 24px 60px rgba(11, 61, 46, 0.16);
        }

        .auth-form {
            padding: 48px 52px;
        }

        .auth-form h1 {
            margin: 0 0 6px;
            font-size: 27px;
            color: #0b3d2e;
        }


        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus {
            -webkit-text-fill-color: #0b3d2e;
            -webkit-box-shadow: 0 0 0 1000px #fbfdfc inset;
            caret-color: #0b3d2e;
            transition: background-color 9999s ease-out 0s;
        }

        .auth-form .lead {
            color: #5b7268;
            font-size: 14.5px;
            margin: 0 0 24px;
        }

        .field {
            margin-bottom: 16px;
        }

        .field label {
            display: block;
            font-weight: 700;
            font-size: 13.5px;
            color: #0b3d2e;
            margin-bottom: 6px;
        }

        .field .input-shell {
            position: relative;
        }

        .field .input-shell::before {
            content: attr(data-icon);
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 15px;
            opacity: 0.55;
            pointer-events: none;
        }

        .field input {
            width: 100%;
            box-sizing: border-box;
            padding: 12px 44px 12px 42px;
            border: 1.5px solid #c8ddd3;
            border-radius: 12px;
            font: inherit;
            font-size: 14.5px;
            background: #fbfdfc;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .field input:focus {
            outline: none;
            border-color: var(--green-500, #27a377);
            box-shadow: 0 0 0 3px rgba(39, 163, 119, 0.16);
            background: white;
        }

        .toggle-pass {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            cursor: pointer;
            font-size: 16px;
            padding: 6px 8px;
            border-radius: 8px;
            opacity: 0.65;
        }

        .toggle-pass:hover {
            opacity: 1;
            background: #eef6f1;
        }

        .auth-submit {
            width: 100%;
            margin-top: 6px;
            padding: 14px;
            border: none;
            border-radius: 12px;
            font: inherit;
            font-size: 15.5px;
            font-weight: 800;
            color: white;
            cursor: pointer;
            background: linear-gradient(135deg, #27a377, #1b6f52);
            box-shadow: 0 8px 20px rgba(30, 133, 99, 0.35);
            transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
        }

        .auth-submit:hover {
            transform: translateY(-2px);
            filter: brightness(1.06);
            box-shadow: 0 12px 26px rgba(30, 133, 99, 0.42);
        }

        .auth-note {
            text-align: center;
            font-size: 13.5px;
            color: #5b7268;
            margin: 18px 0 0;
        }

        .auth-note a {
            color: #1b6f52;
            font-weight: 800;
            text-decoration: none;
        }

        .auth-note a:hover {
            text-decoration: underline;
        }

        .message.error {
            padding: 13px 16px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 18px;
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            color: #b91c1c;
        }

        .auth-side {
            background:
                linear-gradient(160deg, rgba(9, 46, 34, 0.92), rgba(13, 71, 53, 0.96)),
                url('assets/images/PTC_background.png') center / cover;
            color: white;
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
        }

        .auth-side .side-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.22);
            padding: 9px 18px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            width: fit-content;
            margin-bottom: 40px;
        }

        .side-badge .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4ade80;
            box-shadow: 0 0 8px rgba(74, 222, 128, 0.8);
        }

        .auth-side h2 {
            font-size: 30px;
            line-height: 1.3;
            margin: 0 0 16px;
            letter-spacing: -0.3px;
        }

        .auth-side p {
            color: #bcd8ca;
            font-size: 14.5px;
            line-height: 1.7;
            margin: 0;
        }

        .side-divider {
            height: 1px;
            background: linear-gradient(90deg, rgba(255,255,255,0.25), transparent);
            margin: 34px 0;
        }

        .side-points {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .side-points li {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            font-size: 14px;
            font-weight: 600;
            color: #e7f6ee;
            line-height: 1.45;
        }

        .side-points .pt {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            color: #86efac;
            flex-shrink: 0;
        }

        .side-points .pt-num {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 15px;
        }

        .side-points b {
            display: block;
            font-size: 14px;
            color: white;
            margin-bottom: 2px;
        }

        .side-points small {
            display: block;
            font-size: 12.5px;
            font-weight: 400;
            color: #a8c9b8;
            line-height: 1.5;
        }

        .welcome-banner {
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: white;
            padding: 14px 18px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 20px;
            box-shadow: 0 6px 18px rgba(21, 128, 61, 0.28);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @media (max-width: 900px) {
            .auth-card {
                grid-template-columns: 1fr;
            }

            .auth-side {
                display: none;
            }

            .auth-form {
                padding: 34px 26px;
            }
        }
    </style>
</head>

<body class="auth-page">

    <nav class="navbar">
        <a class="brand" href="index.php">
            <img class="brand-logo" src="assets/images/logo-ptc.png" alt="PTC Logo">
            <span class="brand-text">
                Pateros Technological College
                <small>Campus Event Hub</small>
            </span>
        </a>

        <div class="nav-links">
            <a href="index.php">Home</a>
            <a href="index.php#events">Events</a>
            <a href="register.php">Register</a>
        </div>
    </nav>

    <div class="auth-wrap">
        <div class="auth-card">

            <main class="auth-form">
                <h1>Welcome back</h1>
                <p class="lead">Sign in to your Campus Event Hub account.</p>

                <?php if ($error !== ''): ?>
                    <div class="message error"><?= escape($error) ?></div>
                <?php endif; ?>

                <?php if (!empty($_GET['registered'])): ?>
                    <div class="welcome-banner">Account created — please log in.</div>
                <?php endif; ?>

                <form method="POST" action="login.php">

                    <input type="hidden" name="redirect_to"
                           value="<?= escape($_GET['redirect_to'] ?? ($_SERVER['HTTP_REFERER'] ?? '')) ?>">

                    <input type="hidden" name="csrf_token"
                           value="<?= escape($_SESSION['csrf_token']) ?>">

                    <div class="field">
                        <label for="email">Email Address</label>
                        <div class="input-shell" data-icon="@">
                            <input type="email" id="email" name="email"
                                   maxlength="150" autocomplete="email"
                                   placeholder="you@ptc.edu.ph" required>
                        </div>
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <div class="input-shell" data-icon="•">
                            <input type="password" id="password" name="password"
                                   autocomplete="new-password"
                                   placeholder="Your password" required>
                            <button type="button" class="toggle-pass" data-target="password" aria-label="Show password">👁</button>
                        </div>
                    </div>

                    <button type="submit" class="auth-submit">
                        Log In
                    </button>
                </form>

                <p class="auth-note">
                    Don't have an account? <a href="register.php">Register here</a>.
                </p>
            </main>

            <aside class="auth-side">
                <div>
                    <span class="side-badge"><span class="dot"></span> Campus Event Hub</span>
                    <h2>Your campus life, all in one place.</h2>
                    <p>Log in to register for events, check your attendance, and see everything happening at PTC.</p>

                    <div class="side-divider"></div>

                    <ul class="side-points">
                        <li>
                            <span class="pt pt-num">01</span>
                            <div>
                                <b>One-click registration</b>
                                <small>Reserve your slot at any campus event instantly.</small>
                            </div>
                        </li>
                        <li>
                            <span class="pt pt-num">02</span>
                            <div>
                                <b>Personal event history</b>
                                <small>All your registrations and records in one view.</small>
                            </div>
                        </li>
                        <li>
                            <span class="pt pt-num">03</span>
                            <div>
                                <b>Fast, secure sign-in</b>
                                <small>Encrypted session every time you log in.</small>
                            </div>
                        </li>
                        <li>
                            <span class="pt pt-num">04</span>
                            <div>
                                <b>Private by design</b>
                                <small>Your data stays yours — never shared.</small>
                            </div>
                        </li>
                    </ul>
                </div>
            </aside>

        </div>
    </div>

    <footer id="about" style="padding: 8px 0; text-align: center;">
        <p class="footer-school" style="font-size: 12px; line-height: 1.5; margin: 0;">
            Pateros Technological College — Campus Event Hub · &copy; <?= date('Y') ?>
        </p>
    </footer>

    <script>
        document.querySelectorAll('.toggle-pass').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.target);
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.textContent = show ? '×' : '○';
            });
        });
    </script>

</body>
</html>
