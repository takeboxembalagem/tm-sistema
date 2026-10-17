<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
http_response_code(501);
echo json_encode([
    'ok' => false,
    'message' => 'API em preparação. Endpoints serão definidos nas próximas etapas.',
], JSON_UNESCAPED_UNICODE);
