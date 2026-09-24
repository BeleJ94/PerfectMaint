<?php if (!empty($flashSuccess)): ?>
    <div class="alert alert--success" role="status"><i data-lucide="check-circle-2"></i><div><?= e($flashSuccess) ?></div></div>
<?php endif; ?>
<?php if (!empty($flashError)): ?>
    <div class="alert alert--error" role="alert"><i data-lucide="alert-triangle"></i><div><?= e($flashError) ?></div></div>
<?php endif; ?>
