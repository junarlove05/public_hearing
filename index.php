<?php
/**
 * index.php
 * ------------------------------------------------------------------
 * Subsystem #7: Public Hearing and Consultation Management System (LPH)
 * Entry point redirecting to dashboard if authenticated, or login page if not.
 *
 * The official landing page for the Integrated Legislative Management
 * System is located at: http://localhost/legislative/index.php
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(APP_URL . '/dashboard.php');
}

redirect(APP_URL . '/login.php');