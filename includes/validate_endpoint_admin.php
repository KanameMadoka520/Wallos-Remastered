<?php
require_once __DIR__ . '/validate_endpoint.php';
// Check that user is an admin
if ($userId !== 1) {
    wallos_auth_emit_async_error($i18n, 'admin_required', 403, [], 'error');
}
