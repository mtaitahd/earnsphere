        </div>
        <!-- End Page Content -->
    </div>
    <!-- End Main Content -->
    
    <!-- Footer -->
    <footer class="sticky-footer bg-white mt-auto">
        <div class="container my-auto">
            <div class="copyright text-center my-auto">
                <span class="text-gray-500 small">Copyright &copy; <?= date('Y') ?> — EarnSphere</span>
            </div>
        </div>
    </footer>
    <!-- End Footer -->
</div>
<!-- End Content Wrapper -->

</div>
<!-- End Wrapper -->

<?php if ($staffReadOnly ?? false): ?>
<script>
document.querySelectorAll('form[method="POST" i]').forEach(form => {
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(button => { button.disabled = true; });
    form.addEventListener('submit', event => event.preventDefault());
});
document.querySelectorAll('.send-payout-btn').forEach(button => { button.disabled = true; });
</script>
<?php endif; ?>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Sidebar Toggle - Desktop
document.getElementById('sidebarToggle')?.addEventListener('click', function() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('toggled');
});

// Sidebar Toggle - Mobile
document.getElementById('sidebarToggleTop')?.addEventListener('click', function() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('show');
});

// Close sidebar on outside click (mobile)
document.addEventListener('click', function(e) {
    const sidebar = document.querySelector('.sidebar');
    const toggleBtn = document.getElementById('sidebarToggleTop');
    if (window.innerWidth <= 991 && sidebar.classList.contains('show') && 
        !sidebar.contains(e.target) && !toggleBtn?.contains(e.target)) {
        sidebar.classList.remove('show');
    }
});

// Logout confirmation
document.querySelectorAll('[data-target="#logoutModal"]').forEach(el => {
    el.addEventListener('click', function(e) {
        e.preventDefault();
        Swal.fire({
            title: 'Leave admin panel?',
            text: 'You will be logged out.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#72578B',
            cancelButtonColor: '#858796',
            confirmButtonText: 'Yes, logout',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = this.href;
            }
        });
    });
});

// Only show messages not already consumed by the page's inline alerts.
<?php
$pendingFlash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$adminFlashMessages = [];
if (is_array($pendingFlash)) {
    if (is_string($pendingFlash['message'] ?? null) && trim($pendingFlash['message']) !== '') {
        $type = in_array($pendingFlash['type'] ?? '', ['success', 'error', 'warning', 'info'], true) ? $pendingFlash['type'] : 'info';
        $adminFlashMessages[] = ['type' => $type, 'message' => $pendingFlash['message']];
    } else {
        foreach (['success', 'error', 'warning', 'info'] as $type) {
            if (is_string($pendingFlash[$type] ?? null) && trim($pendingFlash[$type]) !== '') {
                $adminFlashMessages[] = ['type' => $type, 'message' => $pendingFlash[$type]];
            }
        }
    }
}
?>
<?php if ($adminFlashMessages): ?>
const adminFlashMessages = <?= json_encode($adminFlashMessages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
(async function () {
    for (const flash of adminFlashMessages) {
        if (typeof Swal === 'undefined') {
            const alert = document.createElement('div');
            alert.className = 'alert alert-' + (flash.type === 'error' ? 'danger' : flash.type);
            alert.setAttribute('role', 'status');
            alert.textContent = flash.message;
            document.getElementById('container-wrapper')?.prepend(alert);
            continue;
        }
        await Swal.fire({
            toast: true,
            position: 'top-end',
            icon: flash.type,
            titleText: flash.message,
            background: '#ffffff',
            color: '#292232',
            customClass: { popup: 'admin-flash-toast' },
            showConfirmButton: false,
            showCloseButton: true,
            timer: 5000,
            timerProgressBar: true
        });
    }
})();
<?php endif; ?>

// Auto-hide alerts after 5 seconds
setTimeout(() => {
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);
</script>
</body>
</html>
