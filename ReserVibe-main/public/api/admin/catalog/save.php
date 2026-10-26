<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Services\CatalogAdminService;
use App\Services\CsrfProtection;

require_once __DIR__ . '/../../bootstrap.php';
Response::requireMethod('POST');
Response::requireRole(['admin']);
$input = Response::input();
if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) Response::error('Invalid or missing CSRF token.', 419);
try {
    $status = isset($input['id']) && $input['id'] !== '' ? 200 : 201;
    Response::success(['accommodation' => (new CatalogAdminService())->save($input)], $status);
} catch (InvalidArgumentException $error) {
    Response::error($error->getMessage(), 422);
}
