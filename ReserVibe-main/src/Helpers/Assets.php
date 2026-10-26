<?php
// src/Helpers/Assets.php

namespace App\Helpers;

/**
 * Versioned asset URLs.
 *
 * The PHP dev server sends no cache headers, so browsers apply heuristic
 * caching to CSS and JS and happily keep serving a stale copy after an edit —
 * you change the stylesheet, reload, and see the old one. Appending the file's
 * modification time makes every edit a brand-new URL, so the browser must
 * re-fetch it.
 *
 * Usage from a view:
 *
 *   <link rel="stylesheet" href="<?= \App\Helpers\Assets::url('/assets/css/style.css') ?>">
 *
 * In production you would swap the mtime for a build hash, but for a project
 * this size the mtime is honest and needs no build step.
 */
class Assets
{
    public static function url(string $path): string
    {
        $file = Config::get('paths.root') . '/public' . $path;
        $stamp = is_file($file) ? filemtime($file) : false;

        return $stamp === false ? $path : $path . '?v=' . $stamp;
    }
}
