<?php
defined('SACCO') or exit;
$code = $status ?? 404;
$msg = $errorMessage ?? ($code === 403 ? 'You do not have permission to view this page.' : 'The page you are looking for does not exist.');
?>
<div class="card">
  <div class="error-page">
    <div class="code text-accent"><?= (int) $code ?></div>
    <h2 class="mt-2"><?= $code === 403 ? 'Access denied' : 'Not found' ?></h2>
    <p class="muted mt-1"><?= e($msg) ?></p>
    <a class="btn btn-primary mt-3" href="index.php"><i class="bi bi-house"></i><span>Back to dashboard</span></a>
  </div>
</div>
