<?php
declare(strict_types=1);

$footerScripts = $footerScripts ?? [];
$dpJs = __DIR__ . '/../assets/js/date-picker.js';
$spJs = __DIR__ . '/../assets/js/slot-picker.js';
?>
        </main>
    </div>
</div>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script src="<?= e(url('/assets/js/date-picker.js')) ?>?v=<?= e(is_file($dpJs) ? (string)filemtime($dpJs) : '1') ?>"></script>
<script src="<?= e(url('/assets/js/slot-picker.js')) ?>?v=<?= e(is_file($spJs) ? (string)filemtime($spJs) : '1') ?>"></script>
<?php foreach ($footerScripts as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
<script>
(function () {
    var mins = parseInt(document.body.getAttribute('data-idle') || '0', 10);
    if (!mins) { return; }
    var timer = null;
    function out() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var f = document.createElement('form');
        f.method = 'post';
        f.action = <?= json_encode(url('/auth/logout.php')) ?>;
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = 'csrf'; i.value = meta ? meta.getAttribute('content') : '';
        f.appendChild(i);
        document.body.appendChild(f);
        f.submit();
    }
    function reset() { clearTimeout(timer); timer = setTimeout(out, mins * 60000); }
    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (ev) { document.addEventListener(ev, reset, { passive: true }); });
    reset();
})();
</script>
<script src="<?= e(url('/assets/js/dashboard.js')) ?>"></script>
</body>
</html>