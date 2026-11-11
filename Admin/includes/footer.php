<?php
declare(strict_types=1);

$footerScripts = $footerScripts ?? [];
?>
        </main>
    </div>
</div>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<?php foreach ($footerScripts as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
<script src="<?= e(url('/assets/js/dashboard.js')) ?>"></script>
</body>
</html>