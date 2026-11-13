<?php
declare(strict_types=1);

$footerScripts = $footerScripts ?? [];
$dpJs = __DIR__ . '/../assets/js/date-picker.js';
?>
        </main>
    </div>
</div>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script src="<?= e(url('/assets/js/date-picker.js')) ?>?v=<?= e(is_file($dpJs) ? (string)filemtime($dpJs) : '1') ?>"></script>
<?php foreach ($footerScripts as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
<script src="<?= e(url('/assets/js/dashboard.js')) ?>"></script>
</body>
</html>