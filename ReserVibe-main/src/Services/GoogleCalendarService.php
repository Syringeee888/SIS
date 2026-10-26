<?php
// src/Services/GoogleCalendarService.php

namespace App\Services;

use App\Helpers\Config;
use App\Repositories\ReservationRepository;
use RuntimeException;

/** Optional Google Calendar OAuth connection and reservation event sync. */
class GoogleCalendarService
{
    private array $settings;

    public function __construct()
    {
        $this->settings = (array) Config::get('google_calendar', []);
    }

    public function configured(): bool
    {
        return (string) ($this->settings['client_id'] ?? '') !== ''
            && (string) ($this->settings['client_secret'] ?? '') !== ''
            && function_exists('curl_init');
    }

    public function connected(): bool
    {
        $token = $this->readToken();
        return isset($token['refresh_token']) || (isset($token['access_token']) && (int) ($token['expires_at'] ?? 0) > time());
    }

    public function beginAuthorization(): never
    {
        if (!$this->configured()) throw new RuntimeException('Add Google OAuth client ID and secret to .env first.');
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $state = bin2hex(random_bytes(32));
        $_SESSION['google_oauth_state'] = $state;
        $query = http_build_query([
            'client_id' => $this->settings['client_id'],
            'redirect_uri' => $this->settings['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.events',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query, true, 302);
        exit;
    }

    public function finishAuthorization(string $code, string $state): void
    {
        if (!$this->configured()) throw new RuntimeException('Add Google OAuth client ID and secret to .env first.');
        if ($code === '' || $state === '') throw new RuntimeException('Google returned an incomplete authorization response.');
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $expected = $_SESSION['google_oauth_state'] ?? '';
        unset($_SESSION['google_oauth_state']);
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $state)) {
            throw new RuntimeException('Google authorization state did not match. Start the connection again.');
        }
        $token = $this->tokenRequest([
            'code' => $code,
            'client_id' => $this->settings['client_id'],
            'client_secret' => $this->settings['client_secret'],
            'redirect_uri' => $this->settings['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);
        if (empty($token['access_token'])) throw new RuntimeException('Google did not return an access token. Check the OAuth consent and redirect URI settings.');
        $previous = $this->readToken();
        if (empty($token['refresh_token']) && !empty($previous['refresh_token'])) $token['refresh_token'] = $previous['refresh_token'];
        $token['expires_at'] = time() + (int) ($token['expires_in'] ?? 3600);
        $this->saveToken($token);
    }

    /** Push all blocking reservations and remove events for cancelled stays. */
    public function syncAll(): array
    {
        if (!$this->connected()) throw new RuntimeException('Connect a Google account before syncing.');
        $reservations = (new ReservationRepository())->all();
        $createdOrUpdated = 0;
        $deleted = 0;
        foreach ($reservations as $reservation) {
            $eventId = $this->eventId((int) ($reservation['id'] ?? 0));
            if (!in_array($reservation['status'] ?? '', ReservationRepository::BLOCKING_STATUSES, true)) {
                $response = $this->calendarRequest('DELETE', '/events/' . $eventId);
                if (!in_array($response['status'], [200, 204, 404, 410], true)) throw new RuntimeException('Google Calendar could not remove event for ' . ($reservation['reference'] ?? 'reservation') . '.');
                if (in_array($response['status'], [200, 204], true)) $deleted++;
                continue;
            }
            $event = [
                'id' => $eventId,
                'summary' => 'ReserVibe stay · ' . (string) ($reservation['reference'] ?? ''),
                'description' => 'Accommodation: ' . (string) ($reservation['accommodation_name'] ?? 'Resort stay')
                    . "\nReservation status: " . (string) ($reservation['status'] ?? 'active'),
                'start' => ['date' => (string) $reservation['check_in']],
                'end' => ['date' => (string) $reservation['check_out']],
                'transparency' => 'opaque',
                'extendedProperties' => ['private' => ['reservibe_reference' => (string) ($reservation['reference'] ?? '')]],
            ];
            $response = $this->calendarRequest('PATCH', '/events/' . $eventId, $event);
            if ($response['status'] === 404) $response = $this->calendarRequest('POST', '/events', $event);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new RuntimeException('Google Calendar could not sync ' . ($reservation['reference'] ?? 'a reservation') . '. Check calendar permissions and API settings.');
            }
            $createdOrUpdated++;
        }
        return ['created_or_updated' => $createdOrUpdated, 'deleted' => $deleted, 'message' => 'Google Calendar sync finished.'];
    }

    private function eventId(int $id): string
    {
        // Google event IDs use the lowercase base32hex alphabet; hex fits it.
        return 'rsv' . substr(hash('sha256', 'reservibe-' . $id), 0, 32);
    }

    private function calendarRequest(string $method, string $path, ?array $body = null): array
    {
        $calendarId = rawurlencode((string) ($this->settings['calendar_id'] ?? 'primary'));
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . $calendarId . $path;
        $accessToken = $this->accessToken();
        $result = $this->http($method, $url, $accessToken, $body);
        if ($result['status'] === 401) {
            $accessToken = $this->refreshToken();
            $result = $this->http($method, $url, $accessToken, $body);
        }
        return $result;
    }

    private function accessToken(): string
    {
        $token = $this->readToken();
        if (!empty($token['access_token']) && (int) ($token['expires_at'] ?? 0) > time() + 60) return (string) $token['access_token'];
        return $this->refreshToken();
    }

    private function refreshToken(): string
    {
        $previous = $this->readToken();
        if (empty($previous['refresh_token'])) throw new RuntimeException('Google connection has no refresh token. Disconnect/reconnect and grant offline access.');
        $fresh = $this->tokenRequest([
            'client_id' => $this->settings['client_id'],
            'client_secret' => $this->settings['client_secret'],
            'refresh_token' => $previous['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);
        if (empty($fresh['access_token'])) throw new RuntimeException('Google access token could not be refreshed. Reconnect the calendar account.');
        $fresh['refresh_token'] = $previous['refresh_token'];
        $fresh['expires_at'] = time() + (int) ($fresh['expires_in'] ?? 3600);
        $this->saveToken($fresh);
        return (string) $fresh['access_token'];
    }

    private function tokenRequest(array $fields): array
    {
        $curl = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($data)) throw new RuntimeException('Google OAuth token request failed. Verify the client credentials and authorized redirect URI.');
        return $data;
    }

    private function http(string $method, string $url, string $accessToken, ?array $body): array
    {
        $curl = curl_init($url);
        $headers = ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'];
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => is_string($raw) ? json_decode($raw, true) : null];
    }

    private function readToken(): array
    {
        $path = (string) ($this->settings['token_path'] ?? '');
        if ($path === '' || !is_file($path)) return [];
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function saveToken(array $token): void
    {
        $path = (string) $this->settings['token_path'];
        $json = json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) throw new RuntimeException('Could not save the private Google connection token.');
        @chmod($path, 0600);
    }
}
