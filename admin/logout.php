<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_boot.php';

session_boot();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    logout_user();
    header('Location: /admin/?bye=1', true, 303);
    exit;
}
header('Location: /admin/', true, 303);
