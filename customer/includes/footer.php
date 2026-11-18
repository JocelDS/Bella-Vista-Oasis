<?php
$hoursText = cu_hours_text();
$arrive = (int)cu_setting('arrival_minutes_before', '15');
$addr = array_filter([cu_setting('address_line1'), cu_setting('address_line2'), cu_setting('address_line3')]);
$phone = cu_setting('contact_phone');
$mail = cu_setting('contact_email');
$parking = cu_setting('parking_note');
?>
<footer class="cu-footer">
    <div class="cu-footer-grid">
        <section class="cu-footer-about">
            <div class="cu-footer-brand">
                <img src="<?= e(cu_url('/assets/images/bella-vista-logo.png')) ?>" alt="" width="44" height="44">
                <span class="serif">Bella Vista Oasis</span>
            </div>
            <p class="cu-footer-text">A private spa sanctuary dedicated to restorative massage, quiet suites and attentive care.</p>
        </section>
        <section>
            <h3>Visit</h3>
            <?php if ($addr): ?>
                <p class="cu-footer-strong"><?= e((string)array_shift($addr)) ?></p>
                <?php foreach ($addr as $line): ?><p class="cu-footer-text tight"><?= e($line) ?></p><?php endforeach; ?>
            <?php endif; ?>
            <?php if ($parking !== ''): ?><p class="cu-footer-text"><?= e($parking) ?></p><?php endif; ?>
        </section>
        <section>
            <h3>Hours &amp; Contact</h3>
            <?php if ($hoursText !== ''): ?><p class="cu-footer-strong"><?= e($hoursText) ?></p><?php endif; ?>
            <?php if ($arrive > 0): ?><p class="cu-footer-text">Please arrive <?= $arrive ?> minutes before your session.</p><?php endif; ?>
            <?php if ($phone !== ''): ?><p class="cu-footer-text tight"><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a></p><?php endif; ?>
            <?php if ($mail !== ''): ?><p class="cu-footer-text tight"><a href="mailto:<?= e($mail) ?>"><?= e($mail) ?></a></p><?php endif; ?>
        </section>
        <section>
            <h3>The Wellness Journal</h3>
            <p class="cu-footer-text">Seasonal offerings and retreat news, delivered occasionally.</p>
            <form id="newsletterForm" class="cu-newsletter" method="post" action="<?= e(cu_url('/auth/newsletter-process.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="email" name="email" id="newsletterEmail" placeholder="Your email address" autocomplete="email" aria-label="Email for newsletter" maxlength="190" required>
                <button type="submit" class="cu-btn cu-btn-primary"><span class="btn-label">Subscribe</span></button>
                <span class="field-error" id="newsletterError" aria-live="polite"></span>
            </form>
        </section>
    </div>
    <div class="cu-footer-bar">
        <span>&copy; <?= date('Y') ?> Bella Vista Oasis Spa. All rights reserved.</span>
        <span class="cu-footer-links">
            <a href="#" data-soon="The Privacy Charter is not published yet.">Privacy</a>
            <a href="#" data-soon="The Terms of Retreat are not published yet.">Terms</a>
        </span>
    </div>
</footer>
<script src="<?= e(cu_url('/assets/js/customer-validation.js')) ?>"></script>
<?php foreach (($pageScripts ?? []) as $s): ?><script src="<?= e(cu_url('/assets/js/' . $s)) ?>"></script><?php endforeach; ?>
</body>
</html>