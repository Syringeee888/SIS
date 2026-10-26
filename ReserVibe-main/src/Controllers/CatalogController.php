<?php
// src/Controllers/CatalogController.php

namespace App\Controllers;

use App\Models\Accommodation;
use App\Helpers\Response;

class CatalogController {
    public function index() {
        $rooms = Accommodation::getAll();
        Response::render('guest/catalog', ['rooms' => $rooms]);
    }
}
