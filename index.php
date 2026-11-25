<?php
/**
 * Root entry redirector.
 * Safely forwards incoming traffic at "/" to the application entry point.
 */
header('Location: stacey_laundry/public/index.php', true, 302);
exit;
