<?
/**
 * Tabbed content template for the Unraid web interface.
 * Accessible, modern, and decoupled from non-tabbed logic.
 */
?>
<div id="displaybox">
    <nav class="tabs" role="tablist" aria-label="Page Tabs">
        <div class="tabs-container">
            <? $i = 0; ?>
            <? foreach ($pages as $page): ?>
                <? if (!isset($page['Title'])) continue; ?>
                <? $title = htmlspecialchars((string)$page['Title']); ?>
                <? $tabId = "tab" . ($i+1); ?>
                <button
                    role="tab"
                    id="<?= $tabId ?>"
                    aria-controls="<?= $tabId ?>-panel"
                    tabindex="<?= $i === 0 ? '0' : '-1' ?>"
                    aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                >
                    <?= tab_title($title, $page['root'], _var($page, 'Tag', false)) ?>
                </button>
            <? $i++; ?>
            <? endforeach; ?>
        </div>
    </nav>

    <? $i = 0; ?>
    <? foreach ($pages as $page): ?>
        <? 
            if (!isset($page['Title'])) {
                continue;
            }
            $title = htmlspecialchars((string)$page['Title']);
            $tabId = "tab" . ($i+1);
            annotate($page['file']);
        ?>
        <section
            id="<?= $tabId ?>-panel"
            role="tabpanel"
            aria-labelledby="<?= $tabId ?>"
            class="content"
            tabindex="0"
            style="display:none;"
        >
            <?= generatePanels($page, $path, $defaultIcon, $docroot) ?>

            <? eval('?>'.generateContent($page)); ?>
        </section>
        <? $i++; ?>
    <? endforeach; ?>
</div>

<script>
const tabs = document.querySelectorAll('.tabs [role="tab"]');
const panels = document.querySelectorAll('[role="tabpanel"]');

// Hide all panels by default (avoid flash)
panels.forEach(panel => panel.style.display = 'none');

// Figure out which cookie to use (matches settab logic)
let cookieName = 'tab';
<?
// Emulate settab's switch logic for cookie name
switch ($myPage['name']) {
    case 'Main':
        echo "cookieName = 'tab';\n";
        break;
    case 'Cache':
    case 'Data':
    case 'Device':
    case 'Flash':
    case 'Parity':
        echo "cookieName = 'one';\n";
        break;
    default:
        echo "cookieName = 'one';\n";
        break;
}
?>

// On load: select correct tab from cookie, or default to first
let activeIdx = 0;
const cookieVal = $.cookie(cookieName);
if (cookieVal) {
    const idx = Array.from(tabs).findIndex(tab => tab.id === cookieVal);
    if (idx !== -1) activeIdx = idx;
} else {
    // If no cookie exists, clear both cookies to match the origial initab function behavior
    $.removeCookie('one');
    $.removeCookie('tab');
}

tabs.forEach((tab, i) => {
    if (i === activeIdx) {
        tab.setAttribute('aria-selected', 'true');
        tab.setAttribute('tabindex', '0');
        panels[i].style.display = 'block';
    } else {
        tab.setAttribute('aria-selected', 'false');
        tab.setAttribute('tabindex', '-1');
        panels[i].style.display = 'none';
    }
});

// On tab click: update cookie and show correct panel
// Also update ARIA
// No content flash

tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => {
            tabs.forEach((t, j) => {
            t.setAttribute('aria-selected', j === i ? 'true' : 'false');
            t.setAttribute('tabindex', j === i ? '0' : '-1');
            panels[j].style.display = j === i ? 'block' : 'none';
        });
        $.cookie(cookieName, tab.id);
        tab.focus();
    });
    tab.addEventListener('keydown', e => {
        let idx = Array.prototype.indexOf.call(tabs, document.activeElement);
        if (e.key === 'ArrowRight') {
            tabs[(idx+1)%tabs.length].focus();
        } else if (e.key === 'ArrowLeft') {
            tabs[(idx-1+tabs.length)%tabs.length].focus();
        }
    });
});
</script>
