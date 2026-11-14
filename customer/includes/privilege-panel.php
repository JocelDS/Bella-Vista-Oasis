<?php
$gap = (int)cu_setting('cleaning_gap_minutes', '30');
$hotline = cu_setting('contact_phone');
$addr1 = cu_setting('address_line1');
?>
<aside class="cu-privilege" aria-labelledby="privTitle">
    <p class="cu-eyebrow pink">&#9679; Sanctuary Privilege Tier</p>
    <h2 id="privTitle" class="serif">Seamless Wellness Flow</h2>
    <ul class="cu-benefits">
        <li>
            <span class="ico-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3m4 0v4h-4m-3-1v1"/></svg></span>
            <div><h3>Synchronized QR Passes</h3><p>Arrival passes linked to your booking, verified by our concierge at check-in.</p></div>
        </li>
        <li>
            <span class="ico-wrap pink"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="8"/><path d="M12 8v8m-3-5h6"/></svg></span>
            <div><h3>Tranquility Points Ledger</h3><p>Points from completed visits are recorded in your ledger and visible once you sign in.</p></div>
        </li>
        <li>
            <span class="ico-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3c3 4 6 6.5 6 10a6 6 0 0 1-12 0c0-3.5 3-6 6-10Z"/></svg></span>
            <div><h3>Priority Suite Purification</h3><p>A <?= $gap ?>-minute cleaning gap is held between sessions in the same suite.</p></div>
        </li>
    </ul>
    <figure class="cu-promo">
        <img src="<?= e(cu_url('/assets/images/spa-interior.png')) ?>" alt="Candles, frangipani flowers and herbal compress on a spa treatment table">
        <figcaption>&ldquo;Pure tranquility begins with a single intentional breath.&rdquo;</figcaption>
    </figure>
    <?php if ($hotline !== ''): ?>
    <div class="cu-hotline">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg>
        <span>Assistance Hotline: <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $hotline)) ?>"><?= e($hotline) ?></a></span>
    </div>
    <?php if ($addr1 !== ''): ?><p class="cu-desk"><?= e(strtoupper($addr1)) ?></p><?php endif; ?>
    <?php endif; ?>
</aside>
