<?php

declare(strict_types=1);

$capture = (string) getenv('PDFTEST_GOTENBERG_CAPTURE');
$request = (string) file_get_contents('php://input');

if ($capture !== '') {
    file_put_contents($capture, $request);
}

header('Content-Type: application/pdf');
http_response_code(200);
echo "%PDF-1.4\n%%EOF\n";
