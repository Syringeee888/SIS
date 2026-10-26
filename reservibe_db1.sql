CREATE DATABASE IF NOT EXISTS reservibe_db;
USE reservibe_db;

-- 1. Users & Staff
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Resort Settings & QR Codes
CREATE TABLE resort_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    description VARCHAR(255),
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
);

-- 3. Accommodation Catalog
CREATE TABLE accommodations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    type ENUM('room', 'cottage', 'package') NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    deposit_rate DECIMAL(5, 2) NOT NULL DEFAULT 50.00,
    guest_limit INT NOT NULL,
    primary_image_path VARCHAR(255) NOT NULL,
    status ENUM('active', 'maintenance', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE accommodation_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accommodation_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    caption VARCHAR(150),
    sort_order INT NOT NULL DEFAULT 1,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (accommodation_id) REFERENCES accommodations(id)
);

-- 4. Guests
CREATE TABLE guests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(60) NOT NULL,
    last_name VARCHAR(60) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(25) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. Temporary Holds
CREATE TABLE temporary_holds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hold_token VARCHAR(64) NOT NULL UNIQUE,
    accommodation_id INT NOT NULL,
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    guest_count INT NOT NULL DEFAULT 1,
    status ENUM('active', 'converted', 'expired', 'released') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (accommodation_id) REFERENCES accommodations(id)
);

-- 6. Reservations
CREATE TABLE reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_ref VARCHAR(25) NOT NULL UNIQUE,
    guest_id INT NOT NULL,
    accommodation_id INT NOT NULL,
    hold_id INT,
    booking_type ENUM('online', 'walk_in') NOT NULL DEFAULT 'online',
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    guest_count INT NOT NULL DEFAULT 1,
    total_cost DECIMAL(10, 2) NOT NULL,
    deposit_required DECIMAL(10, 2) NOT NULL,
    amount_paid DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('unpaid', 'partially_paid', 'paid', 'refunded') NOT NULL DEFAULT 'unpaid',
    reservation_status ENUM('pending', 'confirmed', 'cancelled', 'rescheduled') NOT NULL DEFAULT 'pending',
    guest_status ENUM('pending_arrival', 'arrived', 'checked_out', 'no_show') NOT NULL DEFAULT 'pending_arrival',
    google_calendar_event_id VARCHAR(255),
    calendar_synced_at DATETIME,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guest_id) REFERENCES guests(id),
    FOREIGN KEY (accommodation_id) REFERENCES accommodations(id),
    FOREIGN KEY (hold_id) REFERENCES temporary_holds(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 7. Payment Proofs
CREATE TABLE payment_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255),
    mime_type VARCHAR(50) DEFAULT 'image/jpeg',
    payment_channel ENUM('gcash', 'maya', 'bank_transfer') NOT NULL DEFAULT 'gcash',
    reference_code VARCHAR(60) NOT NULL,
    amount_claimed DECIMAL(10, 2) NOT NULL,
    verification_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    review_notes VARCHAR(255),
    verified_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id),
    FOREIGN KEY (verified_by) REFERENCES users(id)
);

-- 8. Payment Ledger
CREATE TABLE payment_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    payment_proof_id INT,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('gcash', 'maya', 'cash', 'bank_transfer') NOT NULL,
    payment_type ENUM('deposit', 'balance_settlement', 'full_payment', 'refund') NOT NULL,
    reference_note VARCHAR(100),
    recorded_by INT,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id),
    FOREIGN KEY (payment_proof_id) REFERENCES payment_proofs(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id)
);

-- 9. Cancellations & Rescheduling
CREATE TABLE scheduling_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    request_type ENUM('cancellation', 'reschedule') NOT NULL,
    original_check_in DATE NOT NULL,
    original_check_out DATE NOT NULL,
    requested_check_in DATE,
    requested_check_out DATE,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    staff_notes TEXT,
    reviewed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
);

-- 10. Notification Logs
CREATE TABLE notification_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    recipient_email VARCHAR(100) NOT NULL,
    subject VARCHAR(150) NOT NULL,
    notification_type ENUM('booking_confirmation', 'payment_verified', 'reschedule_update', 'cancellation_notice') NOT NULL,
    delivery_status ENUM('queued', 'sent', 'failed') NOT NULL DEFAULT 'sent',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id)
);

-- 11. Seed Data
INSERT INTO resort_settings (setting_key, setting_value, description) VALUES
('payment_qr_image', 'assets/img/sample-qr.png', 'Active GCash/Payment QR Code displayed during booking'),
('default_deposit_percent', '50', 'Required deposit percentage for online reservations'),
('hold_duration_minutes', '15', 'Minutes a temporary slot hold remains active before expiring'),
('hero_banner_image', 'assets/img/hero-pool.jpg', 'Main landing page hero pool background');

INSERT INTO accommodations (code, name, type, description, price, deposit_rate, guest_limit, primary_image_path, status) VALUES
('RM-01', 'Deluxe Poolside Room 1', 'room', 'Air-conditioned room with direct access to the main resort pool.', 3000.00, 50.00, 4, 'assets/img/room-1.jpg', 'active'),
('RM-02', 'Family Suite Room 2', 'room', 'Spacious family suite with two queen beds and private bath.', 4500.00, 50.00, 6, 'assets/img/room-2.jpg', 'active'),
('RM-03', 'Garden View Room 3', 'room', 'Cozy standard room overlooking the tropical garden.', 2500.00, 50.00, 2, 'assets/img/room-3.jpg', 'active'),
('RM-04', 'Barkada Loft Room 4', 'room', 'Large loft-style accommodation ideal for groups.', 5500.00, 50.00, 10, 'assets/img/room-4.jpg', 'active'),
('CTG-GIRAFFE', 'Giraffe Kiddie Pool Cottage', 'cottage', 'Open-air poolside cottage right beside the giraffe water feature.', 1500.00, 50.00, 10, 'assets/img/pool-giraffe.jpg', 'active'),
('PKG-DINO', 'Dino Park & Pool Day Tour Package', 'package', 'Full-day access to the Dinosaur Park attraction, main pool, and pavilion table.', 3500.00, 50.00, 10, 'assets/img/dino-park.jpg', 'active');

INSERT INTO accommodation_photos (accommodation_id, image_path, caption, sort_order) VALUES
(1, 'assets/img/room-1.jpg', 'Deluxe Poolside Room 1 Interior', 1),
(1, 'assets/img/hero-pool.jpg', 'Main Pool View', 2),
(2, 'assets/img/room-2.jpg', 'Family Suite Room 2 Interior', 1),
(3, 'assets/img/room-3.jpg', 'Garden View Room 3 Interior', 1),
(4, 'assets/img/room-4.jpg', 'Barkada Loft Room 4 Interior', 1),
(5, 'assets/img/pool-giraffe.jpg', 'Giraffe Poolside View', 1),
(6, 'assets/img/dino-park.jpg', 'Dino Park Attraction', 1),
(6, 'assets/img/hero-pool.jpg', 'Resort Pool Access Included', 2);
