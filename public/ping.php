<?php

// Lightweight health probe (no Symfony bootstrap). Railway can use /ping.php if needed.
header('Content-Type: application/json');
http_response_code(200);
echo '{"status":"ok"}';
