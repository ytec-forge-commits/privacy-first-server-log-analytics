<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$password = stream_get_contents(STDIN);
if (!is_string($password)) {
    fwrite(STDERR, "Unable to read password from stdin.\n");
    exit(1);
}
$password = rtrim($password, "\r\n");
if (strlen($password) < 16 || strlen($password) > 1024) {
    fwrite(STDERR, "Password must be between 16 and 1024 bytes.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($hash)) {
    fwrite(STDERR, "Password hashing failed.\n");
    exit(1);
}

fwrite(STDOUT, $hash . PHP_EOL);
