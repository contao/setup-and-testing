<?php

declare(strict_types=1);

$directory = dirname(__DIR__);
@mkdir($directory.'/var/sessions/prod', 0700, true);
session_save_path($directory.'/var/sessions/prod');
session_start();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ('/mutate' === $path) {
    $_SESSION['state'] = 'mutated';
    echo 'mutated';

    return;
}

if ('/contao/logout' === $path) {
    session_destroy();
    header('Location: /contao/login');

    return;
}

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (('k.jones' === $username && 'kevinjones' === $password) || ('other' === $username && 'custom' === $password)) {
        session_regenerate_id(true);
        $_SESSION['username'] = $username;
        $_SESSION['state'] = 'clean';
        $logins = is_file($directory.'/logins') ? (int) file_get_contents($directory.'/logins') : 0;
        file_put_contents($directory.'/logins', (string) ($logins + 1));
        header('Location: /contao');

        return;
    }
}

if (isset($_SESSION['username'])) {
    echo '<h1>'.htmlspecialchars($_SESSION['username']).'</h1><button id="profileButton">'.htmlspecialchars($_SESSION['username']).'</button><div id="state">'.$_SESSION['state'].'</div><a href="/contao/logout">Log out</a>';

    return;
}

if ('/contao/login' !== $path) {
    header('Location: /contao/login');

    return;
}

?>
<form method="post"><input name="username"><input name="password" type="password"><button name="login">Login</button></form>
