<?php
/**
 * layouts/footer.php
 * ------------------------------------------------------------------
 * Shared footer + JS includes. Closes the .main-content wrapper that
 * layouts/sidebar.php's including page is expected to have opened.
 * ------------------------------------------------------------------
 */
?>
    <footer class="app-footer text-center py-3">
        <div class="container-fluid">
            <div class="footer-content">
                <div class="footer-divider"></div>
                <div class="footer-text">
                    <span class="footer-copyright">
                        &copy; <?= date('Y') ?> <span class="footer-brand"><?= e(APP_NAME) ?></span>
                    </span>
                    <span class="footer-separator">|</span>
                    <span class="footer-version">Version 1.0</span>
                    <span class="footer-separator">|</span>
                    <span class="footer-status">
                        <span class="status-dot"></span>
                        System Online
                    </span>
                </div>
            </div>
        </div>
    </footer>
  </div><!-- /.main-content -->
</div><!-- /.app-wrapper -->

<script src="<?= e(vendorAsset('bootstrap/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(vendorAsset('jquery/jquery-3.7.1.min.js', 'https://code.jquery.com/jquery-3.7.1.min.js')) ?>"></script>
<script src="<?= e(vendorAsset('datatables/jquery.dataTables.min.js', 'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js')) ?>"></script>
<script src="<?= e(vendorAsset('datatables/dataTables.bootstrap5.min.js', 'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js')) ?>"></script>
<script src="<?= e(vendorAsset('sweetalert2/sweetalert2.min.js', 'https://cdn.jsdelivr.net/npm/sweetalert2@11')) ?>"></script>
<script src="<?= e(vendorAsset('chartjs/chart.umd.min.js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js')) ?>"></script>
<script>
  // FIX (network-error root cause #0 — the primary one): every module's JS
  // file builds its AJAX URLs as `window.APP_URL + '/modules/.../ajax_x.php'`,
  // but window.APP_URL was never actually being defined anywhere in the app.
  // That made every single one of those URLs evaluate to the literal string
  // "undefined/modules/.../ajax_x.php" — a broken relative path that the
  // browser tried to resolve against the current page, essentially always
  // missing. This affected every Add/Edit/Delete/Search/Filter/Sort/
  // Pagination/QR-scan action across every module, which is why data
  // appeared "not saved" and forms intermittently reported network errors.
  window.APP_URL = <?= json_encode(rtrim(APP_URL, '/')) ?>;
  window.APP_CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
</script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>

<?php
// Render any flash messages queued this request as SweetAlert2 toasts.
$flashMessages = getFlashMessages();
if (!empty($flashMessages)):
    foreach ($flashMessages as $msg):
        $icon = in_array($msg['type'], ['danger', 'error'], true) ? 'error'
              : ($msg['type'] === 'warning' ? 'warning' : ($msg['type'] === 'success' ? 'success' : 'info'));
?>
<script>
Swal.fire({
  icon: '<?= e($icon) ?>',
  title: <?= json_encode($msg['message']) ?>,
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timer: 3500,
  timerProgressBar: true
});
</script>
<?php
    endforeach;
endif;
?>

<?php if (!empty($extraJs)) foreach ($extraJs as $js): ?>
<script src="<?= e($js) ?>"></script>
<?php endforeach; ?>

<style>
    /* ============================================================
       FOOTER - Coastal Blue Theme
       Colors: Midnight Blue, White, Gold Accents
       ============================================================ */
    :root {
        --footer-midnight-dark: #0A1628;
        --footer-midnight: #0F2137;
        --footer-midnight-blue: #1A3A5C;
        --footer-midnight-soft: #2C5282;
        --footer-midnight-pale: #4A7EB5;
        --footer-midnight-lighter: #6B9BC7;
        --footer-white: #ffffff;
        --footer-off-white: #F5F8FA;
        --footer-gold: #F5C842;
        --footer-gold-light: #F7D95A;
        --footer-gold-dark: #D4A820;
        --footer-text-light: #E2E8F0;
        --footer-text-muted: #94A3B8;
        --footer-green: #34D399;
    }

    .app-footer {
        background: linear-gradient(180deg, #0A1628 0%, #0F2137 40%, #1A3A5C 100%);
        border-top: 4px solid #F5C842;
        padding: 0.75rem 0;
        margin-top: auto;
        position: relative;
        box-shadow: 0 -4px 30px rgba(10, 22, 40, 0.3);
    }

    /* Subtle glow effect on top border */
    .app-footer::before {
        content: '';
        position: absolute;
        top: -2px;
        left: 50%;
        transform: translateX(-50%);
        width: 60%;
        height: 8px;
        background: radial-gradient(ellipse at center, rgba(245, 200, 66, 0.15) 0%, transparent 70%);
        pointer-events: none;
    }

    .app-footer .footer-content {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .app-footer .footer-divider {
        width: 80px;
        height: 2px;
        background: linear-gradient(90deg, transparent, #F5C842, transparent);
        border-radius: 2px;
        margin-bottom: 0.25rem;
        box-shadow: 0 0 15px rgba(245, 200, 66, 0.15);
    }

    .app-footer .footer-text {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-wrap: wrap;
        gap: 0.5rem 1rem;
        font-size: 0.8rem;
        color: rgba(255, 255, 255, 0.6);
    }

    .app-footer .footer-brand {
        font-weight: 700;
        transition: all 0.3s ease;
        letter-spacing: 0.5px;
        background: linear-gradient(135deg, #F5C842, #F7D95A);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        text-shadow: none;
    }

    .app-footer .footer-brand:hover {
        -webkit-text-fill-color: #F7D95A;
        transform: scale(1.05);
        filter: drop-shadow(0 0 20px rgba(245, 200, 66, 0.15));
    }

    .app-footer .footer-separator {
        color: rgba(255, 255, 255, 0.12);
        font-weight: 300;
    }

    .app-footer .footer-version {
        color: rgba(255, 255, 255, 0.5);
        font-weight: 500;
        font-size: 0.7rem;
        background: rgba(255, 255, 255, 0.06);
        padding: 0.2rem 0.7rem;
        border-radius: 12px;
        border: 1px solid rgba(245, 200, 66, 0.10);
        transition: all 0.3s ease;
    }

    .app-footer .footer-version:hover {
        background: rgba(245, 200, 66, 0.10);
        
        color: #F7D95A;
    }

    .app-footer .footer-status {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        color: rgba(255, 255, 255, 0.6);
        font-size: 0.7rem;
        font-weight: 500;
        background: rgba(255, 255, 255, 0.05);
        padding: 0.2rem 0.7rem 0.2rem 0.5rem;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.06);
        transition: all 0.3s ease;
    }

    .app-footer .footer-status:hover {
        background: rgba(52, 211, 153, 0.08);
        border-color: rgba(52, 211, 153, 0.2);
    }

    .app-footer .status-dot {
        display: inline-block;
        width: 7px;
        height: 7px;
        background: #34D399;
        border-radius: 50%;
        animation: pulse-dot 2s ease-in-out infinite;
        box-shadow: 0 0 12px rgba(52, 211, 153, 0.3);
    }

    @keyframes pulse-dot {
        0%, 100% {
            opacity: 1;
            transform: scale(1);
        }
        50% {
            opacity: 0.4;
            transform: scale(0.7);
        }
    }

    .app-footer .footer-copyright {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        color: rgba(255, 255, 255, 0.5);
    }

    /* Responsive footer */
    @media (max-width: 768px) {
        .app-footer {
            padding: 0.5rem 0;
        }

        .app-footer .footer-text {
            font-size: 0.7rem;
            gap: 0.35rem 0.75rem;
        }

        .app-footer .footer-version,
        .app-footer .footer-status {
            font-size: 0.6rem;
            padding: 0.15rem 0.5rem;
        }

        .app-footer .footer-divider {
            width: 50px;
        }

        .app-footer .footer-separator {
            display: none;
        }
    }

    @media (max-width: 576px) {
        .app-footer .footer-text {
            flex-direction: column;
            gap: 0.25rem;
            font-size: 0.65rem;
        }

        .app-footer .footer-version,
        .app-footer .footer-status {
            font-size: 0.55rem;
            padding: 0.1rem 0.4rem;
        }

        .app-footer .footer-brand {
            font-weight: 700;
            -webkit-text-fill-color: #F5C842;
        }

        .app-footer .footer-divider {
            width: 40px;
        }
    }

    /* Fix for footer position */
    .app-wrapper {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }

    .main-content {
        flex: 1;
    }

    /* Ensure footer stays at bottom */
    .app-footer {
        margin-top: auto;
    }
</style>

</body>
</html>