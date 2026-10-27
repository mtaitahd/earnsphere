<?php if (isset($_SESSION['user_id']) && (($user['role'] ?? $_SESSION['user_role'] ?? '') === 'user')) include __DIR__.'/account_support.php'; ?>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JS -->
    <script src="<?= SITE_URL ?>/assets/js/app.js"></script>
</body>
</html>
