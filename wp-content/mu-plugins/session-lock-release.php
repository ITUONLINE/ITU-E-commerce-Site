<?php
/**
 * Release PHP session locks during admin AJAX requests.
 *
 * Problem: Plugins that call session_start() (Practice Test Manager,
 * Cloudflare Turnstile, etc.) hold a file-based session lock for the
 * entire request. This blocks ALL other requests from the same user
 * (page loads, saves, uploads, other AJAX) until the lock is released.
 *
 * Fix: Close the session early during admin-ajax requests. The session
 * data is already written — we just release the file lock so concurrent
 * requests can proceed.
 */
if (defined('DOING_AJAX') && DOING_AJAX) {
    add_action('admin_init', function () {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }, 999); // Late priority — after plugins have read/written session data
}
