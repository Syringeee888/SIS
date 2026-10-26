<?php
// src/Controllers/ReservationController.php

namespace App\Controllers;

use App\Helpers\Config;
use App\Models\Accommodation;
use App\Helpers\Response;

class ReservationController
{
    public function showForm()
    {
        $id = $_GET['id'] ?? null;
        $room = $id ? Accommodation::find($id) : null;

        if ($id !== null && !$room) {
            Response::render('guest/404', []);
            return;
        }

        Response::render('guest/book', [
            // Room options, add-on prices and payment methods all come from
            // config/catalogue, so the form can never offer a price or a
            // payment method the API would refuse.
            'room' => $room,
            'rooms' => Accommodation::getAll(),
            'addons' => Config::get('addons', []),
            'paymentMethods' => Config::get('payment_methods', []),
            'settings' => [
                'currency_symbol' => Config::get('currency_symbol', 'PHP'),
                'deposit_rate' => Config::get('deposit_rate', 0.5),
                'hold_minutes' => Config::get('hold_minutes', 90),
            ],
        ]);
    }

    public function create()
    {
        // The real write path is POST /api/reservation/create.php, which
        // requires a live hold. Until this controller posts there, it should
        // not pretend a booking was stored.
        Response::render('guest/book', [
            'room' => null,
            'rooms' => Accommodation::getAll(),
            'addons' => Config::get('addons', []),
            'paymentMethods' => Config::get('payment_methods', []),
            'settings' => [
                'currency_symbol' => Config::get('currency_symbol', 'PHP'),
                'deposit_rate' => Config::get('deposit_rate', 0.5),
                'hold_minutes' => Config::get('hold_minutes', 90),
            ],
        ]);
    }
}
