<?php
// src/Models/Reservation.php

namespace App\Models;

class Reservation {
    /**
     * Simulates creating a reservation.
     * Mocked for frontend development.
     */
    public static function create($data) {
        // In a real app, this would INSERT into the database.
        // For the prototype, we just return a random reservation ID to simulate success.
        return rand(1000, 9999);
    }
}
