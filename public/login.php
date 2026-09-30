<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';

startLeaveSession();

const SESSION_TIMEOUT = 1800;

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dashboardUrlForRole(string $role): string
{
    $baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/public/login.php')), '/');

    return match ($role) {
        'admin' => $baseUrl . '/index.php?dashboard=admin&context=admin',
        'hr' => $baseUrl . '/index.php?dashboard=hr&context=hr',
        'manager' => $baseUrl . '/index.php?dashboard=manager&context=manager',
        default => $baseUrl . '/index.php?dashboard=employee&context=employee',
    };
}

$error = '';
$email = '';

if (isset($_GET['timeout'])) {
    $error = 'ເຊສຊັນໝົດອາຍຸ ກະລຸນາເຂົ້າສູ່ລະບົບໃໝ່';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $rememberMe = (string) ($_POST['remember_me'] ?? '') === '1';

    if ($email === '' || $password === '') {
        $error = 'ກະລຸນາກອກອີເມວ ແລະ ລະຫັດຜ່ານ';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'ຮູບແບບອີເມວບໍ່ຖືກຕ້ອງ';
    } else {
        try {
            $stmt = db()->prepare(
                'SELECT id, employee_code, first_name, last_name, email, password, avatar, role, department_id, status
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, (string) $user['password'])) {
                $error = 'ອີເມວ ຫຼື ລະຫັດຜ່ານບໍ່ຖືກຕ້ອງ';
            } elseif ($user['status'] !== 'active') {
                $error = 'ບັນຊີຜູ້ໃຊ້ນີ້ຖືກປິດໃຊ້ງານ ກະລຸນາຕິດຕໍ່ຜູ້ດູແລລະບົບ';
            } else {
                $role = (string) $user['role'];
                session_write_close();
                startLeaveSession($role, $rememberMe ? LEAVE_REMEMBER_SESSION_LIFETIME : 0);
                session_regenerate_id(true);

                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'employee_code' => (string) $user['employee_code'],
                    'name' => trim($user['first_name'] . ' ' . $user['last_name']),
                    'email' => (string) $user['email'],
                    'avatar' => (string) ($user['avatar'] ?? ''),
                    'role' => $role,
                    'department_id' => $user['department_id'] !== null ? (int) $user['department_id'] : null,
                ];
                $_SESSION['last_activity'] = time();
                $_SESSION['remember_me'] = $rememberMe;

                header('Location: ' . dashboardUrlForRole($role));
                exit;
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'ບໍ່ສາມາດເຊື່ອມຕໍ່ລະບົບໄດ້ ກະລຸນາລອງໃໝ່ອີກຄັ້ງ';
        }
    }
}

$baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/public/login.php')), '/');
$loginBackgroundImage = '';
try {
    $loginBackgroundStmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = "login_background_image" LIMIT 1');
    $loginBackgroundStmt->execute();
    $candidateImage = (string) ($loginBackgroundStmt->fetchColumn() ?: '');
    if (preg_match('#^uploads/login_backgrounds/[a-f0-9]{32}\.(jpe?g|png|webp)$#i', $candidateImage)) {
        $loginBackgroundImage = $candidateImage;
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
}
?>
<!doctype html>
<html lang="lo">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ເຂົ້າສູ່ລະບົບ | ລະບົບລາພັກ</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --violet-900: #4c2e8f;
            --violet-700: #6b3fc4;
            --violet-500: #8257e5;
            --violet-300: #a888ec;
            --violet-100: #efe9fc;
            --ink: #241a3c;
            --muted: #8f88a3;
            --white: #ffffff;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--violet-100);
            font-family: 'Noto Sans Lao', 'Phetsarath OT', sans-serif;
            padding: 24px;
        }

        .auth-shell {
            width: 100%;
            max-width: 980px;
            min-height: 600px;
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 30px 70px -20px rgba(76, 46, 143, 0.45);
            background: var(--white);
        }

        /* ---------- Left: hero panel ---------- */
        .auth-hero {
            position: relative;
            background: linear-gradient(150deg, var(--violet-900) 0%, var(--violet-700) 55%, var(--violet-500) 100%);
            color: var(--white);
            padding: 56px 48px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            overflow: hidden;
        }

        <?php if ($loginBackgroundImage !== ''): ?>
        .auth-hero {
            background-image: linear-gradient(150deg, rgba(76, 46, 143, 0.22) 0%, rgba(107, 63, 196, 0.15) 55%, rgba(130, 87, 229, 0.10) 100%), url("<?= h($loginBackgroundImage) ?>");
            background-size: cover;
            background-position: center;
        }
        .auth-hero .blob { opacity: 0.07; }
        .auth-hero .wave { opacity: 0.18; }
        <?php endif; ?>

        .auth-hero .blob {
            position: absolute;
            border-radius: 50%;
            opacity: 0.16;
            background: var(--white);
        }
        .auth-hero .blob-1 { width: 340px; height: 340px; top: -140px; left: -120px; }
        .auth-hero .blob-2 { width: 220px; height: 220px; bottom: -90px; left: 40px; opacity: 0.12; }
        .auth-hero .blob-3 { width: 120px; height: 120px; bottom: 60px; right: -30px; opacity: 0.1; }

        .auth-hero .wave {
            position: absolute;
            inset: 0;
            opacity: 0.5;
        }

        .auth-hero .dot-grid {
            position: absolute;
            top: 44px;
            right: 40px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }
        .auth-hero .dot-grid span {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.55);
            display: block;
        }

        .auth-hero .plus {
            position: absolute;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 700;
            font-size: 22px;
        }
        .auth-hero .plus-1 { top: 74px; left: 96px; }
        .auth-hero .plus-2 { bottom: 128px; left: 200px; font-size: 16px; }

        .auth-hero .ring {
            position: absolute;
            border: 2px solid rgba(255, 255, 255, 0.45);
            border-radius: 50%;
        }
        .auth-hero .ring-1 { width: 16px; height: 16px; top: 118px; left: 220px; }
        .auth-hero .ring-2 { width: 20px; height: 20px; bottom: 44px; left: 44px; }

        .auth-hero .brand {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .auth-hero .brand-mark {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.16);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .auth-hero .brand-name {
            font-weight: 700;
            font-size: 18px;
            letter-spacing: 0.02em;
        }
        .auth-hero .brand-sub {
            font-size: 12.5px;
            color: rgba(255, 255, 255, 0.75);
        }

        .auth-hero .hero-copy {
            position: relative;
            z-index: 2;
            max-width: 320px;
            margin-top: auto;
            margin-bottom: 18px;
            text-shadow: 0 3px 5px rgba(12, 7, 30, 0.92), 0 8px 24px rgba(12, 7, 30, 0.82), 0 0 2px rgba(12, 7, 30, 0.96);
        }
        .auth-hero .hero-copy h1 {
            font-size: 34px;
            font-weight: 800;
            margin: 0 0 14px;
            line-height: 1.25;
        }
        .auth-hero .hero-copy p {
            margin: 0;
            font-size: 15px;
            line-height: 1.7;
            color: rgba(255, 255, 255, 0.82);
        }

        .auth-hero .hero-footer {
            position: relative;
            z-index: 2;
            margin-top: 0;
            font-size: 12.5px;
            color: rgba(255, 255, 255, 0.6);
        }

        /* ---------- Right: form panel ---------- */
        .auth-form {
            padding: 56px 48px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .auth-form h2 {
            font-size: 26px;
            font-weight: 800;
            color: var(--ink);
            margin: 0 0 6px;
        }
        .auth-form .lede {
            color: var(--muted);
            font-size: 14px;
            margin: 0 0 28px;
        }

        .field {
            margin-bottom: 16px;
        }
        .field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: 6px;
        }
        .field .control {
            position: relative;
        }
        .field .control i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--violet-500);
            font-size: 14px;
        }
        .field input {
            width: 100%;
            padding: 13px 16px 13px 42px;
            border-radius: 12px;
            border: 1.5px solid #e7e2f5;
            background: #faf9fd;
            font-size: 14.5px;
            font-family: inherit;
            color: var(--ink);
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .field input::placeholder { color: #b6aed1; }
        .field input:focus {
            border-color: var(--violet-500);
            box-shadow: 0 0 0 4px var(--violet-100);
            background: var(--white);
        }

        .row-between {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 4px 0 24px;
            font-size: 13px;
        }
        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
        }
        .remember input { accent-color: var(--violet-500); }
        .row-between a {
            color: var(--violet-700);
            text-decoration: none;
            font-weight: 600;
        }
        .row-between a:hover { text-decoration: underline; }

        .btn-submit {
            width: 100%;
            border: none;
            border-radius: 12px;
            padding: 14px;
            background: linear-gradient(120deg, var(--violet-700), var(--violet-500));
            color: var(--white);
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.01em;
            cursor: pointer;
            box-shadow: 0 14px 24px -10px rgba(107, 63, 196, 0.65);
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 28px -10px rgba(107, 63, 196, 0.75);
        }
        .btn-submit:active { transform: translateY(0); }

        .switch-line {
            text-align: center;
            margin-top: 22px;
            font-size: 13.5px;
            color: var(--muted);
        }
        .switch-line a {
            color: var(--violet-700);
            font-weight: 700;
            text-decoration: none;
        }
        .switch-line a:hover { text-decoration: underline; }

        .session-tip {
            display: flex;
            align-items: flex-start;
            gap: 7px;
            margin: 18px 0 0;
            color: #7b7192;
            font-size: 12px;
            line-height: 1.6;
        }

        .session-tip i { margin-top: 3px; color: var(--violet-500); }

        .alert-error {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fdecea;
            border: 1px solid #f6c6c1;
            color: #b3261e;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 13.5px;
            margin-bottom: 20px;
        }

        @media (max-width: 860px) {
            .auth-shell {
                grid-template-columns: 1fr;
                min-height: unset;
            }
            .auth-hero { padding: 40px 32px; min-height: 220px; }
            .auth-hero .hero-copy h1 { font-size: 26px; }
            .auth-form { padding: 40px 32px; }
        }
    </style>
</head>
<body>
    <main class="auth-shell">
        <section class="auth-hero">
            <span class="blob blob-1"></span>
            <span class="blob blob-2"></span>
            <span class="blob blob-3"></span>
            <span class="plus plus-1">+</span>
            <span class="plus plus-2">+</span>
            <span class="ring ring-1"></span>
            <span class="ring ring-2"></span>
            <div class="dot-grid">
                <?php for ($i = 0; $i < 12; $i++): ?><span></span><?php endfor; ?>
            </div>

            <div class="brand">
                <div class="brand-mark"><i class="fa-solid fa-briefcase"></i></div>
                <div>
                    <div class="brand-name">ລະບົບລາພັກ</div>
                    <div class="brand-sub">ລະບົບລາພັກພາຍໃນອົງກອນ</div>
                </div>
            </div>

            <div class="hero-copy">
                <h1>ຍິນດີຕ້ອນຮັບກັບຄືນ!</h1>
                <p>ເຂົ້າສູ່ລະບົບເພື່ອສົ່ງຄຳຂໍລາພັກ ຕິດຕາມສະຖານະ ແລະ ເບິ່ງຍອດວັນລາຂອງທ່ານ.</p>
            </div>

            <div class="hero-footer">&copy; <?= date('Y') ?> ລະບົບລາພັກ</div>
        </section>

        <section class="auth-form">
            <h2>ເຂົ້າສູ່ລະບົບ</h2>
            <p class="lede">ກະລຸນາປ້ອນອີເມວ ແລະ ລະຫັດຜ່ານຂອງທ່ານ</p>

            <?php if ($error !== ''): ?>
                <div class="alert-error">
                    <i class="fa-solid fa-circle-exclamation mt-1"></i>
                    <span><?= h($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" novalidate>
                <div class="field">
                    <label for="email">ອີເມວ</label>
                    <div class="control">
                        <i class="fa-regular fa-envelope"></i>
                        <input type="email" id="email" name="email" value="<?= h($email) ?>" placeholder="employee@example.com" required autofocus>
                    </div>
                </div>

                <div class="field">
                    <label for="password">ລະຫັດຜ່ານ</label>
                    <div class="control">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder="ປ້ອນລະຫັດຜ່ານ" required>
                    </div>
                </div>

                <div class="row-between">
                    <label class="remember">
                        <input type="checkbox" name="remember_me" value="1">
                        ຈົດຈຳຂ້ອຍ
                    </label>
        
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-right-to-bracket me-2"></i>ເຂົ້າສູ່ລະບົບ
                </button>
            </form>

            <p class="session-tip"><i class="fa-solid fa-circle-info"></i> ສາມາດເປີດໜ້ານີ້ໃນແຖບໃໝ່ ແລະ ເຂົ້າລະບົບດ້ວຍບົດບາດອື່ນໄດ້ພ້ອມກັນ</p>


            </p>
        </section>
    </main>
</body>
</html>
