<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

session_unset();
session_destroy();

session_start();
setFlash('success', 'Anda berhasil logout.');
redirect('login.php');
