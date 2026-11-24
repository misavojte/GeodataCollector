<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);
require APP_ROOT . '/app/bootstrap.php';

if (Auth::check()) {
    // ?v=…: a unique URL bypasses any stale copy of index.html in the browser cache
    // (the "can't sign in after a week" scenario that only a hard refresh fixed).
    header('Location: ./?v=' . bin2hex(random_bytes(4))); // app directory → Apache DirectoryIndex (index.html SPA)
    exit;
}

// Language: the zvk_lang cookie shared with the SPA (set there by the switcher in the menu).
// ?lang=cs|en sets the cookie and redirects to the clean URL – the cookie is then
// the only place that is read. NOT httponly: the app's JS reads and writes it.
if (($_GET['lang'] ?? '') !== '' && in_array($_GET['lang'], ['cs', 'en'], true)) {
    setcookie('zvk_lang', $_GET['lang'], [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    header('Location: login.php');
    exit;
}
$cookieLang = $_COOKIE['zvk_lang'] ?? '';
if (in_array($cookieLang, ['cs', 'en'], true)) {
    $lang = $cookieLang;
} else {
    // No choice yet → the configured default (app.default_language). Persist it in the cookie
    // so the SPA starts in the same language as the sign-in page.
    $lang = Config::defaultLanguage();
    setcookie('zvk_lang', $lang, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

// Sign-in page strings (API errors are mapped by their machine code below).
$L = [
    'cs' => [
        'title'        => 'Přihlášení',
        'username'     => 'Přihlašovací jméno',
        'password'     => 'Heslo',
        'submit'       => 'Přihlásit se',
        'note'         => 'Sběr dat probíhá na počítači. Účty zakládá správce projektu.',
        'err_csrf'     => 'Přihlašovací stránka mezitím vypršela. Zkuste to prosím ještě jednou.',
        'err_bad'      => 'Neplatné přihlašovací jméno nebo heslo.',
        'err_attempts' => 'Příliš mnoho neúspěšných pokusů o přihlášení. Zkuste to prosím za několik minut.',
        'err_generic'  => 'Přihlášení se nezdařilo. Zkuste to prosím znovu.',
        'switch'       => 'Switch to English',
        'switch_to'    => 'en',
    ],
    'en' => [
        'title'        => 'Sign in',
        'username'     => 'Username',
        'password'     => 'Password',
        'submit'       => 'Sign in',
        'note'         => 'Data collection happens on a computer. Accounts are created by the project administrator.',
        'err_csrf'     => 'The sign-in page has expired. Please try once more.',
        'err_bad'      => 'Invalid username or password.',
        'err_attempts' => 'Too many failed sign-in attempts. Please try again in a few minutes.',
        'err_generic'  => 'Sign-in failed. Please try again.',
        'switch'       => 'Přepnout do češtiny',
        'switch_to'    => 'cs',
    ],
][$lang];

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::requireValid($_POST['csrf'] ?? null);
        Auth::attemptLogin(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')
        );
        // Write the session to disk BEFORE redirecting. Otherwise the browser may send
        // the next request before PHP manages to write it at script shutdown (slow
        // shared storage), and a fresh sign-in would look like a signed-out state.
        session_write_close();
        // li=1: lets the SPA know it is "arriving right after a successful sign-in" (one-off self-healing
        // on 401). v=…: a unique URL bypasses a stale cached copy of index.html.
        header('Location: ./?li=1&v=' . bin2hex(random_bytes(4)));
        exit;
    } catch (HttpException $e) {
        // The API sends machine codes with an English message – here they are mapped to the page
        // language. Expired CSRF token (old page left open): the form is re-rendered
        // with a fresh token, so resubmitting is enough – tell the user that in plain words.
        $errorMap = [
            'csrf'              => $L['err_csrf'],
            'bad_credentials'   => $L['err_bad'],
            'too_many_attempts' => $L['err_attempts'],
        ];
        $error = $errorMap[$e->errorCode] ?? $e->getMessage();
    } catch (Throwable $e) {
        error_log('Login error: ' . $e->getMessage());
        $error = $L['err_generic'];
    }
}

Page::securityHeaders();
header('Content-Type: text/html; charset=utf-8');
$csrf = Csrf::token();
$appName = (string) Config::get('app.name', 'Geodata Collector');
$appSubtitle = (string) Config::get('app.subtitle', '');
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Page::e($L['title']) ?></title>
    <link rel="icon" href="logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="fonts/poppins.css">
    <link rel="stylesheet" href="assets/login.css">
</head>
<body class="login-page">
    <main class="login-card" role="main">
        <img class="login-logo" src="logo.svg" alt="" width="50" height="55">
        <h1 class="login-title"><?= Page::e($appName) ?></h1>
        <?php if ($appSubtitle !== ''): ?><p class="login-subtitle"><?= Page::e($appSubtitle) ?></p><?php endif; ?>

        <?php if ($error !== null): ?>
            <div class="alert alert-error" role="alert"><?= Page::e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" class="login-form" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= Page::e($csrf) ?>">

            <label for="username"><?= Page::e($L['username']) ?></label>
            <input type="text" id="username" name="username" required autofocus
                   value="<?= Page::e((string) ($_POST['username'] ?? '')) ?>"
                   autocapitalize="none" autocomplete="username">

            <label for="password"><?= Page::e($L['password']) ?></label>
            <input type="password" id="password" name="password" required
                   autocomplete="current-password">

            <button type="submit" class="btn btn-primary btn-block"><?= Page::e($L['submit']) ?></button>
        </form>

        <p class="login-note"><?= Page::e($L['note']) ?></p>
        <p class="login-lang"><a href="login.php?lang=<?= $L['switch_to'] ?>"><?= Page::e($L['switch']) ?></a></p>
    </main>
</body>
</html>
