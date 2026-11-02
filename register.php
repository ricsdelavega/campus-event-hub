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

$fullName = '';
$email = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullName === '' || strlen($fullName) > 100) {
        $errors[] = 'Enter a name of up to 100 characters.';
    }

    if (
        $email === '' ||
        strlen($email) > 150 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'Enter a valid email address of up to 150 characters.';
    }

    if (!is_string($password) || strlen($password) < 8) {
        $errors[] = 'Your password must be at least 8 characters long.';
    }

    if (!is_string($confirmPassword) ||
        $password !== $confirmPassword) {
        $errors[] = 'The passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $sql = "INSERT INTO users
(full_name, email, password_hash)
VALUES
(:full_name, :email, :password_hash)";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => $passwordHash
            ]);

            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $pdo->lastInsertId();
            $_SESSION['user_name'] = $fullName;
            $_SESSION['user_role'] = 'student';

            $_SESSION['flash_welcome'] = 'Welcome, ' . $fullName
                . '! Your account has been created — you are now logged in.';

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: index.php');
            exit;

        } catch (PDOException $e) {

            if ($e->getCode() === '23000') {
                $errors[] = 'That email address may already be registered.';
            } else {
                error_log($e->getMessage());
                $errors[] = 'Something went wrong. Please try again later.';
            }
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
    <title>Student Registration | Pateros Technological College</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body.auth-page {
            background:
                radial-gradient(circle at 12% 18%, rgba(39, 163, 119, 0.22), transparent 42%),
                radial-gradient(circle at 88% 82%, rgba(249, 176, 0, 0.14), transparent 40%),
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
            grid-template-columns: 0.85fr 1.15fr;
            box-shadow: 0 24px 60px rgba(11, 61, 46, 0.16);
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

        .auth-form {
            padding: 48px 52px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 20px;
        }

        .form-grid .full {
            grid-column: 1 / -1;
        }

        .auth-form h1 {
            margin: 0 0 6px;
            font-size: 26px;
            color: #0b3d2e;
        }

        .auth-form .lead {
            color: #5b7268;
            font-size: 14.5px;
            margin: 0 0 22px;
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


        .strength {
            display: flex;
            gap: 5px;
            margin-top: 7px;
            align-items: center;
        }

        .strength .seg {
            height: 5px;
            flex: 1;
            border-radius: 999px;
            background: #e2efe8;
            transition: background 0.25s ease;
        }

        .strength .seg.on-1 { background: #ef4444; }
        .strength .seg.on-2 { background: #f59e0b; }
        .strength .seg.on-3 { background: #22c55e; }
        .strength .seg.on-4 { background: #16a34a; }

        .strength .hint {
            font-size: 11.5px;
            font-weight: 700;
            color: #7a8d80;
            margin-left: 6px;
            white-space: nowrap;
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

        .message.success,
        .message.error {
            padding: 13px 16px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 18px;
        }

        .message.success {
            background: #e8f8ef;
            border: 1.5px solid #9de0bd;
            color: #15803d;
        }

        .message.error {
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            color: #b91c1c;
        }

        .message.error ul {
            margin: 6px 0 0;
            padding-left: 18px;
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

            .form-grid {
                grid-template-columns: 1fr;
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
            <a href="login.php">Login</a>
        </div>
    </nav>

    <div class="auth-wrap">
        <div class="auth-card">

            <aside class="auth-side">
                <div>
                    <span class="side-badge"><span class="dot"></span> Campus Event Hub</span>
                    <h2>Join the PTC student community.</h2>
                    <p>Create your free account to register for campus events, track your attendance, and stay updated on every activity.</p>

                    <div class="side-divider"></div>

                    <ul class="side-points">
                        <li>
                            <span class="pt pt-num">01</span>
                            <div>
                                <b>Instant sign-up</b>
                                <small>Create your account in under a minute — no approval waiting period.</small>
                            </div>
                        </li>
                        <li>
                            <span class="pt pt-num">02</span>
                            <div>
                                <b>One-click registration</b>
                                <small>Reserve your slot at any campus event with a single click.</small>
                            </div>
                        </li>
                        <li>
                            <span class="pt pt-num">03</span>
                            <div>
                                <b>Personal event history</b>
                                <small>View your registrations and attendance records anytime.</small>
                            </div>
                        </li>
                        <li>
                            <span class="pt pt-num">04</span>
                            <div>
                                <b>Secure &amp; private</b>
                                <small>Your credentials are encrypted and never shared.</small>
                            </div>
                        </li>
                    </ul>
                </div>
            </aside>

            <main class="auth-form">
                <h1>Create a Student Account</h1>
                <p class="lead">Register to get started with campus events.</p>

                <?php if (!empty($errors)): ?>
                    <div class="message error">
                        <strong>Please check the following:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= escape($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php" id="reg-form">

                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                    <div class="form-grid">
                        <div class="field full">
                            <label for="full_name">Full Name</label>
                            <div class="input-shell" data-icon="✎">
                                <input type="text" id="full_name" name="full_name"
                                       maxlength="100" autocomplete="name"
                                       placeholder="Juan Dela Cruz"
                                       value="<?= escape($fullName) ?>" required>
                            </div>
                        </div>

                        <div class="field full">
                            <label for="email">Email Address</label>
                            <div class="input-shell" data-icon="@">
                                <input type="email" id="email" name="email"
                                       maxlength="150" autocomplete="email"
                                       placeholder="you@ptc.edu.ph" required>
                            </div>
                        </div>

                        <div class="field">
                            <label for="password">Password</label>
                            <div class="input-shell" data-icon="●">
                                <input type="password" id="password" name="password"
                                       minlength="8" autocomplete="new-password"
                                       placeholder="At least 8 characters" required>
                                <button type="button" class="toggle-pass" data-target="password" aria-label="Show password">👁</button>
                            </div>
                            <div class="strength" id="strength">
                                <span class="seg"></span><span class="seg"></span><span class="seg"></span><span class="seg"></span>
                                <span class="hint" id="strength-hint">Password strength</span>
                            </div>
                        </div>

                        <div class="field">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="input-shell" data-icon="●">
                                <input type="password" id="confirm_password" name="confirm_password"
                                       minlength="8" autocomplete="new-password"
                                       placeholder="Re-type your password" required>
                                <button type="button" class="toggle-pass" data-target="confirm_password" aria-label="Show password">👁</button>
                            </div>
                        </div>

                        <div class="full">
                            <button type="submit" class="auth-submit">
                                Create Account
                            </button>
                        </div>
                    </div>
                </form>

                <p class="auth-note">
                    Already have an account? <a href="login.php">Log in here</a>.
                </p>
            </main>

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


        const pwInput = document.getElementById('password');
        const segs = document.querySelectorAll('#strength .seg');
        const hint = document.getElementById('strength-hint');

        pwInput.addEventListener('input', () => {
            const v = pwInput.value;
            let score = 0;
            if (v.length >= 8) score++;
            if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
            if (/\d/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v) || v.length >= 14) score++;

            segs.forEach((s, i) => {
                s.className = 'seg' + (i < score ? ' on-' + score : '');
            });

            const labels = ['Too short', 'Weak', 'Okay', 'Good', 'Strong 💪'];
            hint.textContent = v.length === 0 ? 'Password strength' : labels[score];
        });
    </script>

</body>
</html>
