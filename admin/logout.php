<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';

if (!is_post()) {
    http_response_code(405);
    exit('Método não permitido.');
}

csrf_check();
auth_logout();

redirect('login.php');
