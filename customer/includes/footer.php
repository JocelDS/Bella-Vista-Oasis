<?php
$hoursText = cu_hours_text();
$arrive = (int)cu_setting('arrival_minutes_before', '15');
$svcNames = [];
try {
    $svcNames = db()->query('SELECT service_name FROM services ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $svcNames = [];
}
$addr = array_filter([cu_setting('address_line1'), cu_setting('address_line2'), cu_setting('address_line3')]);
$phone = cu_setting('contact_phone');
$mail = cu_setting('contact_email');
$parking = cu_setting('parking_note');
?>
<footer class="cu-footer">
    <div class="cu-footer-grid">
        <section>
            <div class="cu-footer-brand">
                <img src="<?= e(cu_url('/assets/images/bella-vista-logo.png')) ?>" alt="" width="44" height="44">
                <span class="serif">Bella Vista</span>
            </div>
            <p class="cu-footer-text">A private spa sanctuary dedicated to restorative massage, quiet suites and attentive care.</p>
            <?php if ($svcNames): ?>
                <p class="cu-eyebrow">Our Suites</p>
                <p class="cu-footer-text"><?= e(implode(' • ', $svcNames)) ?></p>
            <?php endif; ?>
        </section>
        <section>
            <h3>Sanctuary Hours</h3>
            <?php if ($hoursText !== ''): ?><p class="cu-footer-strong"><?= e($hoursText) ?></p><?php endif; ?>
            <?php if ($arrive > 0): ?><p class="cu-footer-text">Please arrive <?= $arrive ?> minutes before your scheduled session.</p><?php endif; ?>
            <?php if ($phone !== '' || $mail !== ''): ?>
                <p class="cu-eyebrow">Direct Concierge</p>
                <?php if ($phone !== ''): ?><p class="cu-footer-strong"><?= e($phone) ?></p><?php endif; ?>
                <?php if ($mail !== ''): ?><p class="cu-footer-text"><a href="mailto:<?= e($mail) ?>"><?= e($mail) ?></a></p><?php endif; ?>
            <?php endif; ?>
        </section>
        <section>
            <h3>Location &amp; Haven</h3>
            <?php if ($addr): ?>
                <p class="cu-footer-strong"><?= e((string)array_shift($addr)) ?></p>
                <?php foreach ($addr as $line): ?><p class="cu-footer-text tight"><?= e($line) ?></p><?php endforeach; ?>
            <?php endif; ?>
            <?php if ($parking !== ''): ?>
                <p class="cu-eyebrow">Valet &amp; Arrival</p>
                <p class="cu-footer-text"><?= e($parking) ?></p>
            <?php endif; ?>
        </section>
        <section>
            <h3>The Wellness Gazette</h3>
            <p class="cu-footer-text">Receive seasonal offerings and retreat news from Bella Vista Oasis.</p>
            <form id="newsletterForm" class="cu-newsletter" method="post" action="<?= e(cu_url('/auth/newsletter-process.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="email" name="email" id="newsletterEmail" placeholder="Enter your personal email" autocomplete="email" aria-label="Email for newsletter" maxlength="190" required>
                <span class="field-error" id="newsletterError" aria-live="polite"></span>
                <button type="submit" class="cu-btn cu-btn-primary"><span class="btn-label">Subscribe to Journal</span></button>
            </form>
        </section>
    </div>
    <div class="cu-footer-bar">
        <span>&copy; <?= date('Y') ?> Bella Vista Oasis Spa. All rights reserved.</span>
        <span class="cu-footer-links">
            <a href="#" data-soon="The Privacy Charter is not published yet.">Privacy Charter</a>
            <a href="#" data-soon="The Terms of Retreat are not published yet.">Terms of Retreat</a>
        </span>
    </div>
</footer>
<script src="<?= e(cu_url('/assets/js/customer-validation.js')) ?>"></script>
<?php foreach (($pageScripts ?? []) as $s): ?><script src="<?= e(cu_url('/assets/js/' . $s)) ?>"></script><?php endforeach; ?>
</body>
</html>