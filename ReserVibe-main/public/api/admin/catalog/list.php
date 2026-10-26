<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Repositories\AccommodationRepository;

require_once __DIR__ . '/../../bootstrap.php';
Response::requireMethod('GET');
Response::requireRole(['admin']);
Response::success(['accommodations' => (new AccommodationRepository())->all()]);
