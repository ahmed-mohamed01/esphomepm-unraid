<?php
/**
 * Non-tabbed content template for the Unraid web interface.
 * Renders all pages in sequence without tabs, using original per-page logic.
 */
?>
<div id="displaybox">
    <div class="content">
        <? foreach ($pages as $page): ?>
            <? annotate($page['file']); ?>
            <? includePageStylesheets($page); ?>

            <? if (isset($page['Title'])): ?>
                <div class="title">
                    <?= tab_title($page['Title'], $page['root'], _var($page, 'Tag', false)) ?>
                </div>
            <? endif; ?>

            <?= generatePanels($page, $path, $defaultIcon, $docroot, true) ?>

            <? eval('?>'.generateContent($page)); ?>
        <? endforeach; ?>
    </div>
</div>
