<?php
/**
 * index.php
 * ------------------------------------------------------------------
 * Main landing page for the Integrated Legislative Management System.
 *
 * Provides access to the five separate but integrated subsystem
 * websites:
 *
 * #1  Ordinance and Resolution Life Cycle Management
 * #3  Legislative Agenda and Calendar Management
 * #5  Voting, Quorum, and Decision Support
 * #7  Public Hearing and Consultation Management
 * #10 Citizen Engagement and Public Feedback Management
 *
 * The Public Hearing subsystem is currently available.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/auth.php';

$loggedIn = isLoggedIn();

/**
 * Subsystem URLs
 * ------------------------------------------------------------------
 * Replace the placeholder URLs once the separate subsystem websites
 * are available.
 */
$subsystems = [
    [
        'number'      => '#1',
        'code'        => 'ordinance_resolution',
        'title'       => 'Ordinance and Resolution Life Cycle Management System',
        'short_title' => 'Ordinance and Resolution',
        'description' => 'Manage the complete life cycle of ordinances and resolutions, from drafting and committee review to enactment, publication, implementation, and amendment tracking.',
        'icon'        => 'bi-file-earmark-text',
        'url'         => 'http://localhost/orlms/',
        'enabled'     => true,
        'status'      => 'Available',
        'modules'     => [
            'Ordinance Drafting and Encoding',
            'Resolution Drafting and Submission',
            'Review and Committee Endorsement',
            'Approval and Enactment',
            'Publication and Dissemination',
            'Implementation Monitoring',
            'Amendment and Revision Tracking',
        ],
    ],
    [
        'number'      => '#3',
        'code'        => 'agenda',
        'title'       => 'Legislative Agenda and Calendar Management System',
        'short_title' => 'Agenda and Calendar',
        'description' => 'Manage legislative priorities, schedules, meetings, deadlines, and coordination between the executive and legislative offices.',
        'icon'        => 'bi-calendar3',
        'url'         => 'http://localhost/lacms/',
        'enabled'     => true,
        'status'      => 'Available',
        'modules'     => [
            'Legislative Priority Setting',
            'Calendar Scheduling',
            'Meeting Coordination',
            'Deadline Tracking',
            'Executive-Legislative Synchronization',
        ],
    ],
    [
        'number'      => '#5',
        'code'        => 'voting',
        'title'       => 'Voting, Quorum, and Decision Support System',
        'short_title' => 'Voting and Quorum',
        'description' => 'Support quorum verification, legislative voting, vote tallying, decision recording, validation, and official reporting.',
        'icon'        => 'bi-check2-square',
        'url'         => 'http://localhost/vqdss/',
        'enabled'     => true,
        'status'      => 'Available',
        'modules'     => [
            'Quorum Verification',
            'Voting Management — Manual and Electronic',
            'Vote Tallying',
            'Decision Recording',
            'Result Validation and Reporting',
        ],
    ],
    [
        'number'      => '#7',
        'code'        => 'hearing',
        'title'       => 'Public Hearing and Consultation Management System',
        'short_title' => 'Public Hearing',
        'description' => 'Coordinate public hearings, stakeholder invitations, registration, attendance, feedback, issue logging, responses, and action tracking.',
        'icon'        => 'bi-people',
        'url'         => $loggedIn
            ? APP_URL . '/dashboard.php'
            : APP_URL . '/login.php',
        'enabled'     => true,
        'status'      => 'Available',
        'modules'     => [
            'Hearing Scheduling',
            'Stakeholder Invitation and Registration',
            'Attendance Tracking',
            'Public Feedback Collection',
            'Issue Logging',
            'Response and Action Tracking',
        ],
    ],
    [
        'number'      => '#10',
        'code'        => 'citizen',
        'title'       => 'Citizen Engagement and Public Feedback Management System',
        'short_title' => 'Citizen Engagement',
        'description' => 'Manage public feedback, citizen proposals, complaints, moderation, official responses, and engagement analytics.',
        'icon'        => 'bi-chat-square-heart',
        'url'         => 'http://localhost/citizen-engagement/',
        'enabled'     => false,
        'status'      => 'Coming Soon',
        'modules'     => [
            'Public Feedback Submission',
            'Proposal and Suggestion Management',
            'Complaint and Issue Tracking',
            'Moderation and Validation',
            'Response Management',
            'Citizen Engagement Analytics',
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Integrated Legislative Management System</title>

    <link
        href="<?= e(vendorAsset(
            'bootstrap/bootstrap.min.css',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'
        )) ?>"
        rel="stylesheet"
    >

    <link
        href="<?= e(vendorAsset(
            'bootstrap-icons/bootstrap-icons.css',
            'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css'
        )) ?>"
        rel="stylesheet"
    >

    <link href="assets/css/style.css" rel="stylesheet">

    <style>
        :root {
            --portal-blue: #1a56db;
            --portal-blue-dark: #172f76;
            --portal-blue-deep: #102354;
            --portal-blue-light: #3b82f6;
            --portal-yellow: #fbbf24;
            --portal-yellow-light: #fde68a;
            --portal-white: #ffffff;
            --portal-background: #f5f7fb;
            --portal-text: #1f2937;
            --portal-muted: #6b7280;
            --portal-border: #e5e7eb;
            --portal-success: #059669;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--portal-background);
            color: var(--portal-text);
            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        a {
            text-decoration: none;
        }

        /* Navigation */

        .portal-navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            min-height: 76px;
            background: rgba(255, 255, 255, 0.94);
            border-bottom: 1px solid rgba(229, 231, 235, 0.8);
            backdrop-filter: blur(16px);
            box-shadow: 0 8px 30px rgba(15, 35, 84, 0.06);
        }

        .portal-navbar .container {
            min-height: 76px;
        }

        .portal-brand {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            color: var(--portal-blue-dark);
            font-weight: 800;
        }

        .portal-logo {
            width: 48px;
            height: 48px;
            padding: 5px;
            border-radius: 50%;
            background: white;
            border: 2px solid rgba(251, 191, 36, 0.55);
            box-shadow: 0 6px 20px rgba(26, 86, 219, 0.14);
        }

        .portal-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }

        .portal-brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.1;
        }

        .portal-brand-title {
            font-size: 0.98rem;
        }

        .portal-brand-subtitle {
            margin-top: 0.25rem;
            color: var(--portal-muted);
            font-size: 0.72rem;
            font-weight: 500;
        }

        .navbar-nav .nav-link {
            position: relative;
            margin: 0 0.25rem;
            padding: 0.55rem 0.8rem !important;
            color: #4b5563;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: 9px;
            transition: 0.25s ease;
        }

        .navbar-nav .nav-link:hover {
            color: var(--portal-blue);
            background: #eff6ff;
        }

        .btn-portal-login {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-height: 43px;
            padding: 0.65rem 1rem;
            color: white;
            background: linear-gradient(
                135deg,
                var(--portal-blue),
                var(--portal-blue-dark)
            );
            border: none;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 700;
            box-shadow: 0 6px 18px rgba(26, 86, 219, 0.24);
            transition: 0.25s ease;
        }

        .btn-portal-login:hover {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(26, 86, 219, 0.3);
        }

        /* Hero */

        .portal-hero {
            position: relative;
            display: flex;
            align-items: center;
            min-height: 680px;
            padding: 145px 0 90px;
            overflow: hidden;
            background:
                radial-gradient(
                    circle at 82% 17%,
                    rgba(251, 191, 36, 0.22),
                    transparent 24%
                ),
                radial-gradient(
                    circle at 15% 82%,
                    rgba(59, 130, 246, 0.22),
                    transparent 25%
                ),
                linear-gradient(
                    135deg,
                    var(--portal-blue-deep) 0%,
                    var(--portal-blue-dark) 47%,
                    var(--portal-blue) 100%
                );
        }

        .portal-hero::before {
            content: "";
            position: absolute;
            top: -220px;
            right: -180px;
            width: 580px;
            height: 580px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 50%;
        }

        .portal-hero::after {
            content: "";
            position: absolute;
            bottom: -230px;
            left: -160px;
            width: 520px;
            height: 520px;
            border: 1px solid rgba(251, 191, 36, 0.13);
            border-radius: 50%;
        }

        .hero-content,
        .hero-panel {
            position: relative;
            z-index: 2;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            margin-bottom: 1.5rem;
            padding: 0.55rem 0.85rem;
            color: var(--portal-yellow-light);
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            backdrop-filter: blur(10px);
        }

        .hero-title {
            max-width: 750px;
            margin-bottom: 1.25rem;
            color: white;
            font-size: clamp(2.5rem, 5vw, 4.6rem);
            line-height: 1.05;
            font-weight: 850;
            letter-spacing: -0.04em;
        }

        .hero-title .highlight {
            color: var(--portal-yellow);
        }

        .hero-description {
            max-width: 680px;
            margin-bottom: 2rem;
            color: rgba(255, 255, 255, 0.82);
            font-size: 1.08rem;
            line-height: 1.8;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.9rem;
        }

        .btn-hero-primary,
        .btn-hero-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            min-height: 51px;
            padding: 0.8rem 1.3rem;
            border-radius: 12px;
            font-size: 0.94rem;
            font-weight: 700;
            transition: 0.25s ease;
        }

        .btn-hero-primary {
            color: var(--portal-blue-dark);
            background: var(--portal-yellow);
            border: 1px solid var(--portal-yellow);
            box-shadow: 0 10px 30px rgba(251, 191, 36, 0.23);
        }

        .btn-hero-primary:hover {
            color: var(--portal-blue-dark);
            background: var(--portal-yellow-light);
            transform: translateY(-2px);
        }

        .btn-hero-secondary {
            color: white;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
        }

        .btn-hero-secondary:hover {
            color: white;
            background: rgba(255, 255, 255, 0.16);
            transform: translateY(-2px);
        }

        .hero-panel {
            padding: 1.4rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 24px;
            box-shadow: 0 30px 80px rgba(5, 17, 46, 0.28);
            backdrop-filter: blur(18px);
        }

        .hero-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.15rem;
            color: white;
        }

        .hero-panel-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 750;
        }

        .hero-panel-status {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: #a7f3d0;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .system-preview-list {
            display: grid;
            gap: 0.75rem;
        }

        .system-preview-item {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.85rem;
            color: rgba(255, 255, 255, 0.8);
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-radius: 14px;
        }

        .system-preview-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 39px;
            height: 39px;
            flex: 0 0 39px;
            color: var(--portal-yellow);
            background: rgba(251, 191, 36, 0.12);
            border-radius: 11px;
        }

        .system-preview-name {
            margin-bottom: 0.1rem;
            color: white;
            font-size: 0.78rem;
            font-weight: 650;
        }

        .system-preview-label {
            color: rgba(255, 255, 255, 0.55);
            font-size: 0.67rem;
        }

        /* Section */

        .portal-section {
            padding: 90px 0;
        }

        .section-heading {
            max-width: 720px;
            margin: 0 auto 3rem;
            text-align: center;
        }

        .section-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 0.85rem;
            color: var(--portal-blue);
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .section-title {
            margin-bottom: 0.9rem;
            color: var(--portal-blue-dark);
            font-size: clamp(1.9rem, 3vw, 2.8rem);
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .section-description {
            color: var(--portal-muted);
            line-height: 1.75;
        }

        /* System cards */

        .system-card {
            position: relative;
            height: 100%;
            padding: 1.6rem;
            overflow: hidden;
            background: white;
            border: 1px solid var(--portal-border);
            border-radius: 20px;
            box-shadow: 0 12px 35px rgba(15, 35, 84, 0.07);
            transition:
                transform 0.3s ease,
                box-shadow 0.3s ease,
                border-color 0.3s ease;
        }

        .system-card:hover {
            transform: translateY(-7px);
            border-color: rgba(26, 86, 219, 0.24);
            box-shadow: 0 22px 55px rgba(15, 35, 84, 0.13);
        }

        .system-card.available {
            border: 2px solid rgba(26, 86, 219, 0.45);
            box-shadow: 0 18px 50px rgba(26, 86, 219, 0.14);
        }

        .system-card.available::before {
            content: "";
            position: absolute;
            top: -65px;
            right: -65px;
            width: 145px;
            height: 145px;
            background: rgba(251, 191, 36, 0.12);
            border-radius: 50%;
        }

        .system-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .system-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 58px;
            height: 58px;
            color: var(--portal-blue);
            background: #eff6ff;
            border-radius: 16px;
            font-size: 1.5rem;
        }

        .system-number {
            color: #94a3b8;
            font-size: 0.78rem;
            font-weight: 800;
        }

        .system-status {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            margin-bottom: 0.8rem;
            padding: 0.38rem 0.65rem;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 750;
        }

        .system-status.available {
            color: #047857;
            background: #d1fae5;
        }

        .system-status.coming-soon {
            color: #92400e;
            background: #fef3c7;
        }

        .system-card-title {
            margin-bottom: 0.65rem;
            color: var(--portal-blue-dark);
            font-size: 1.12rem;
            line-height: 1.45;
            font-weight: 780;
        }

        .system-card-description {
            min-height: 72px;
            margin-bottom: 1.2rem;
            color: var(--portal-muted);
            font-size: 0.88rem;
            line-height: 1.65;
        }

        .module-list {
            display: grid;
            gap: 0.58rem;
            margin: 0 0 1.35rem;
            padding: 0;
            list-style: none;
        }

        .module-list li {
            display: flex;
            align-items: flex-start;
            gap: 0.55rem;
            color: #4b5563;
            font-size: 0.8rem;
            line-height: 1.45;
        }

        .module-list i {
            margin-top: 0.1rem;
            color: var(--portal-yellow);
            font-size: 0.85rem;
        }

        .system-card-footer {
            margin-top: auto;
        }

        .btn-system {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            min-height: 45px;
            padding: 0.65rem 1rem;
            border-radius: 11px;
            font-size: 0.84rem;
            font-weight: 700;
            transition: 0.25s ease;
        }

        .btn-system-active {
            color: white;
            background: linear-gradient(
                135deg,
                var(--portal-blue),
                var(--portal-blue-dark)
            );
            border: none;
            box-shadow: 0 7px 20px rgba(26, 86, 219, 0.21);
        }

        .btn-system-active:hover {
            color: white;
            transform: translateY(-2px);
        }

        .btn-system-disabled {
            color: #9ca3af;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            cursor: not-allowed;
        }

        /* About panel */

        .about-section {
            background: white;
        }

        .about-panel {
            padding: 2.5rem;
            background:
                radial-gradient(
                    circle at 90% 10%,
                    rgba(251, 191, 36, 0.16),
                    transparent 22%
                ),
                linear-gradient(
                    135deg,
                    #f8fbff,
                    #eef4ff
                );
            border: 1px solid #dbeafe;
            border-radius: 24px;
        }

        .about-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            color: var(--portal-blue);
            background: white;
            border-radius: 17px;
            box-shadow: 0 10px 30px rgba(26, 86, 219, 0.13);
            font-size: 1.55rem;
        }

        .about-title {
            color: var(--portal-blue-dark);
            font-weight: 800;
        }

        .about-text {
            color: var(--portal-muted);
            line-height: 1.8;
        }

        .platform-stat {
            padding: 1.1rem;
            background: white;
            border: 1px solid #dbeafe;
            border-radius: 15px;
            text-align: center;
        }

        .platform-stat-value {
            margin-bottom: 0.2rem;
            color: var(--portal-blue);
            font-size: 1.65rem;
            font-weight: 850;
        }

        .platform-stat-label {
            color: var(--portal-muted);
            font-size: 0.76rem;
            font-weight: 650;
        }

        /* Footer */

        .portal-footer {
            padding: 2rem 0;
            color: rgba(255, 255, 255, 0.72);
            background: var(--portal-blue-deep);
            font-size: 0.82rem;
        }

        .footer-brand {
            color: white;
            font-weight: 750;
        }

        .footer-secure {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: var(--portal-yellow-light);
        }

        /* Responsive */

        @media (max-width: 991.98px) {
            .portal-navbar {
                position: sticky;
            }

            .portal-hero {
                padding-top: 85px;
            }

            .hero-panel {
                margin-top: 3rem;
            }

            .portal-navbar .navbar-collapse {
                margin-top: 0.85rem;
                padding: 0.8rem;
                background: white;
                border: 1px solid var(--portal-border);
                border-radius: 13px;
                box-shadow: 0 12px 30px rgba(15, 35, 84, 0.08);
            }

            .portal-navbar .btn-portal-login {
                width: 100%;
                margin-top: 0.5rem;
            }
        }

        @media (max-width: 767.98px) {
            .portal-hero {
                min-height: auto;
                padding: 100px 0 75px;
            }

            .hero-title {
                font-size: 2.5rem;
            }

            .portal-section {
                padding: 70px 0;
            }

            .system-card-description {
                min-height: auto;
            }

            .about-panel {
                padding: 1.6rem;
            }
        }

        @media (max-width: 575.98px) {
            .portal-brand-subtitle {
                display: none;
            }

            .portal-brand-title {
                max-width: 190px;
                font-size: 0.82rem;
            }

            .portal-logo {
                width: 43px;
                height: 43px;
            }

            .hero-actions {
                flex-direction: column;
            }

            .btn-hero-primary,
            .btn-hero-secondary {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand-lg portal-navbar">
    <div class="container">
        <a class="navbar-brand portal-brand" href="<?= e(APP_URL) ?>/index.php">
            <span class="portal-logo">
                <img
                    src="<?= e(APP_URL) ?>/assets/images/manila.png"
                    alt="System logo"
                >
            </span>

            <span class="portal-brand-text">
                <span class="portal-brand-title">
                    Integrated Legislative System
                </span>

                <span class="portal-brand-subtitle">
                    City Government Legislative Platform
                </span>
            </span>
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#portalNavigation"
            aria-controls="portalNavigation"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="portalNavigation">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <li class="nav-item">
                    <a class="nav-link" href="#home">
                        Home
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#subsystems">
                        Subsystems
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#about">
                        About
                    </a>
                </li>
            </ul>

            <a
                class="btn-portal-login ms-lg-3"
                href="<?= e(
                    $loggedIn
                        ? APP_URL . '/dashboard.php'
                        : APP_URL . '/login.php'
                ) ?>"
            >
                <i class="bi <?= $loggedIn ? 'bi-grid' : 'bi-box-arrow-in-right' ?>"></i>

                <?= $loggedIn ? 'Open Dashboard' : 'Sign In' ?>
            </a>
        </div>
    </div>
</nav>

<main>
    <section class="portal-hero" id="home">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <div class="hero-content">
                        <div class="hero-badge">
                            <i class="bi bi-buildings"></i>
                            Integrated Legislative Digital Platform
                        </div>

                        <h1 class="hero-title">
                            One platform for
                            <span class="highlight">
                                responsive legislation
                            </span>
                        </h1>

                        <p class="hero-description">
                            Access interconnected legislative systems for ordinance
                            and resolution management, agenda scheduling, public hearings,
                            voting, decision support, and citizen engagement.
                        </p>

                        <div class="hero-actions">
                            <a
                                href="#subsystems"
                                class="btn-hero-primary"
                            >
                                <i class="bi bi-grid-3x3-gap"></i>
                                Explore Subsystems
                            </a>

                            <a
                                href="<?= e(
                                    $loggedIn
                                        ? APP_URL . '/dashboard.php'
                                        : APP_URL . '/login.php'
                                ) ?>"
                                class="btn-hero-secondary"
                            >
                                <i class="bi <?= $loggedIn ? 'bi-speedometer2' : 'bi-shield-lock' ?>"></i>

                                <?= $loggedIn
                                    ? 'Continue to Dashboard'
                                    : 'Secure User Login'
                                ?>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="hero-panel">
                        <div class="hero-panel-header">
                            <h2 class="hero-panel-title">
                                Legislative Subsystems
                            </h2>

                            <span class="hero-panel-status">
                                <i class="bi bi-circle-fill"></i>
                                Platform Online
                            </span>
                        </div>

                        <div class="system-preview-list">
                            <?php foreach ($subsystems as $system): ?>
                                <div class="system-preview-item">
                                    <span class="system-preview-icon">
                                        <i class="bi <?= e($system['icon']) ?>"></i>
                                    </span>

                                    <span>
                                        <span class="system-preview-name d-block">
                                            <?= e($system['short_title']) ?>
                                        </span>

                                        <span class="system-preview-label">
                                            <?= e($system['status']) ?>
                                        </span>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="portal-section" id="subsystems">
        <div class="container">
            <div class="section-heading">
                <div class="section-eyebrow">
                    <i class="bi bi-diagram-3"></i>
                    Integrated Systems
                </div>

                <h2 class="section-title">
                    Access the legislative subsystems
                </h2>

                <p class="section-description">
                    Each subsystem operates as a separate website while
                    sharing a common legislative database and integrated
                    user access.
                </p>
            </div>

            <div class="row g-4 justify-content-center">
                <?php foreach ($subsystems as $system): ?>
                    <div class="col-xl-4 col-md-6">
                        <article
                            class="system-card d-flex flex-column
                                <?= $system['enabled'] ? 'available' : '' ?>"
                        >
                            <div class="system-card-header">
                                <span class="system-icon">
                                    <i class="bi <?= e($system['icon']) ?>"></i>
                                </span>

                                <span class="system-number">
                                    <?= e($system['number']) ?>
                                </span>
                            </div>

                            <div>
                                <span
                                    class="system-status
                                    <?= $system['enabled']
                                        ? 'available'
                                        : 'coming-soon'
                                    ?>"
                                >
                                    <i class="bi <?= $system['enabled']
                                        ? 'bi-check-circle-fill'
                                        : 'bi-clock'
                                    ?>"></i>

                                    <?= e($system['status']) ?>
                                </span>
                            </div>

                            <h3 class="system-card-title">
                                <?= e($system['title']) ?>
                            </h3>

                            <p class="system-card-description">
                                <?= e($system['description']) ?>
                            </p>

                            <ul class="module-list">
                                <?php foreach ($system['modules'] as $module): ?>
                                    <li>
                                        <i class="bi bi-check2-circle"></i>
                                        <span><?= e($module) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <div class="system-card-footer mt-auto">
                                <?php if ($system['enabled']): ?>
                                    <a
                                        href="<?= e($system['url']) ?>"
                                        class="btn-system btn-system-active"
                                    >
                                        <i class="bi bi-box-arrow-up-right"></i>

                                        <?= $loggedIn
                                            ? 'Open Subsystem'
                                            : 'Access Subsystem'
                                        ?>
                                    </a>
                                <?php else: ?>
                                    <span
                                        class="btn-system btn-system-disabled"
                                        aria-disabled="true"
                                    >
                                        <i class="bi bi-hourglass-split"></i>
                                        Development in Progress
                                    </span>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="portal-section about-section" id="about">
        <div class="container">
            <div class="about-panel">
                <div class="row align-items-center g-4">
                    <div class="col-lg-7">
                        <div class="d-flex align-items-start gap-3">
                            <span class="about-icon flex-shrink-0">
                                <i class="bi bi-diagram-3"></i>
                            </span>

                            <div>
                                <h2 class="about-title mb-3">
                                    A unified legislative ecosystem
                                </h2>

                                <p class="about-text mb-0">
                                    The platform connects separate legislative
                                    websites through shared records, common user
                                    access, consistent workflows, and a central
                                    database. This allows every subsystem to
                                    operate independently while remaining part
                                    of one integrated legislative process.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="row g-3">
                            <div class="col-4">
                                <div class="platform-stat">
                                    <div class="platform-stat-value">5</div>
                                    <div class="platform-stat-label">
                                        Subsystems
                                    </div>
                                </div>
                            </div>

                            <div class="col-4">
                                <div class="platform-stat">
                                    <div class="platform-stat-value">1</div>
                                    <div class="platform-stat-label">
                                        Shared Database
                                    </div>
                                </div>
                            </div>

                            <div class="col-4">
                                <div class="platform-stat">
                                    <div class="platform-stat-value">1</div>
                                    <div class="platform-stat-label">
                                        Unified Access
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="portal-footer">
    <div class="container">
        <div
            class="d-flex flex-column flex-md-row
                   align-items-center justify-content-between gap-3"
        >
            <div>
                <span class="footer-brand">
                    Integrated Legislative Management System
                </span>

                <span class="d-block mt-1">
                    &copy; <?= date('Y') ?>. All rights reserved.
                </span>
            </div>

            <div class="footer-secure">
                <i class="bi bi-shield-check"></i>
                Secure and integrated government platform
            </div>
        </div>
    </div>
</footer>

<script src="<?= e(vendorAsset(
    'bootstrap/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'
)) ?>"></script>

</body>
</html>