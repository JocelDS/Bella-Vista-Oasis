<?php
declare(strict_types=1);

function icon(string $name, string $class = 'ico'): string
{
    static $paths = [
        'grid'       => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'clipboard'  => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9zM9 12h6M9 16h4"/>',
        'calendar'   => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'door'       => '<path d="M6 21V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17M3 21h18"/><circle cx="14.5" cy="12.5" r=".8" fill="currentColor"/>',
        'user'       => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/>',
        'users'      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-5.5 6.5-5.5s6.5 1.9 6.5 5.5"/><path d="M16 4.7a3.5 3.5 0 0 1 0 6.6M18 14.8c2 .6 3.5 2.2 3.5 5.2"/>',
        'award'      => '<circle cx="12" cy="9" r="6"/><path d="m8.5 14 -1.5 7 5-3 5 3-1.5-7"/>',
        'gift'       => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v8h14v-8M12 8v12M12 8S10.5 3 8 4.5 8.5 8 12 8Zm0 0s1.5-5 4-3.5S15.5 8 12 8Z"/>',
        'chart'      => '<path d="M4 20V4M4 20h16"/><path d="m8 15 3.5-4 3 2.5L19 8"/>',
        'gear'       => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2.5M12 18.5V21M3 12h2.5M18.5 12H21M5.6 5.6l1.8 1.8M16.6 16.6l1.8 1.8M18.4 5.6l-1.8 1.8M7.4 16.6l-1.8 1.8"/>',
        'search'     => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'bell'       => '<path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15zM10 21a2 2 0 0 0 4 0"/>',
        'menu'       => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'chevron'    => '<path d="m6 9 6 6 6-6"/>',
        'logout'     => '<path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3M16 8l4 4-4 4M20 12H9"/>',
        'plus'       => '<path d="M12 5v14M5 12h14"/>',
        'arrow'      => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'leaf'       => '<path d="M5 19c0-8 5-14 15-14 0 10-6 15-14 15"/><path d="M5 19c3-5 6-8 10-10"/>',
        'check'      => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'check-ring' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/>',
        'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'wallet'     => '<path d="M4 7a2 2 0 0 1 2-2h11v4"/><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M16 13.5h2.5"/>',
        'close'      => '<path d="M6 6l12 12M18 6 6 18"/>',
        'shield'     => '<path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'pulse'      => '<path d="M3 12h4l2.5-6 4 12 2.5-6H21"/>',
        'dots'       => '<circle cx="12" cy="5" r="1.2" fill="currentColor"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/><circle cx="12" cy="19" r="1.2" fill="currentColor"/>',
        'sliders'    => '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
        'refresh'    => '<path d="M20 11a8 8 0 0 0-14-4.5L4 9M4 5v4h4M4 13a8 8 0 0 0 14 4.5L20 15M20 19v-4h-4"/>',
        'download'   => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19h14"/>',
        'qr'         => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h3M20 17v4"/>',
        'bed'        => '<path d="M3 18V7M3 14h18v4M21 14v-2a3 3 0 0 0-3-3h-7v5"/><circle cx="7" cy="11" r="1.5"/>',
    ];

    $p = $paths[$name] ?? '';
    return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}