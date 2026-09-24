<?php

// Routeur pour le serveur PHP intégré (développement) : php -S 127.0.0.1:8080 -t public bin/dev-router.php
$file = __DIR__ . '/../public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($file)) {
    return false;
}
require __DIR__ . '/../public/index.php';
