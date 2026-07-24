<?php
/**
 * EarnSphere - Security Headers
 * Include this file at the top of every page to set security headers
 */

if (!headers_sent()) {
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');

    // XSS Protection
    header('X-XSS-Protection: 1; mode=block');

    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions Policy
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    // Content Security Policy
    header("Content-Security-Policy: default-src 'self' https:; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'self';");

    // Strict Transport Security (HSTS)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    // Cache control for HTML pages
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}
