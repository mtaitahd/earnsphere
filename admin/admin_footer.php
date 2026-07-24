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

// SweetAlert flash messages
<?php if (isset($_SESSION['flash'])): ?>
    <?php $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: '<?= $flash['type'] ?>',
        title: '<?= addslashes($flash['message']) ?>',
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true
    });
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
