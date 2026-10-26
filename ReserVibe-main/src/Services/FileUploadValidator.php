<?php
// src/Services/FileUploadValidator.php

namespace App\Services;

use App\Helpers\Config;
use App\Helpers\Text;
use InvalidArgumentException;
use RuntimeException;

/** Sniff, limit and store guest payment-proof images outside the public web root. */
class FileUploadValidator
{
    public function store(array $file): array
    {
        if (!isset($file['error']) || is_array($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Choose a valid receipt image to upload.');
        }
        $tmp = $file['tmp_name'] ?? '';
        $size = (int) ($file['size'] ?? 0);
        $max = (int) Config::get('uploads.max_bytes', 5242880);
        if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp) || $size < 1 || $size > $max) {
            throw new InvalidArgumentException('The image must be under ' . (int) ceil($max / 1048576) . ' MB.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!is_string($mime) || !isset($extensions[$mime]) || !in_array($mime, Config::get('uploads.allowed_mime', []), true)) {
            throw new InvalidArgumentException('Upload a JPEG, PNG, or WebP receipt image.');
        }
        $dir = (string) Config::get('uploads.dir');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not prepare private receipt storage.');
        }
        $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $filename)) {
            throw new RuntimeException('Could not store the receipt image.');
        }
        @chmod($dir . '/' . $filename, 0600);
        $original = basename((string) ($file['name'] ?? 'receipt'));
        $original = function_exists('iconv') ? iconv('UTF-8', 'UTF-8//IGNORE', $original) : $original;
        if (!is_string($original) || $original === '') $original = 'receipt';
        $original = Text::substring(preg_replace('/[\x00-\x1F\x7F]/u', '', $original) ?? 'receipt', 0, 180);
        return ['filename' => $filename, 'mime' => $mime, 'original_name' => $original];
    }
}
