<?php

if (!function_exists('render_page_navigation')) {
    function render_page_navigation($title, array $sections)
    {
        if (empty($sections)) {
            return;
        }
        ?>
        <aside class="page-nav-shell">
            <nav class="page-nav" aria-label="Page navigation">
                <div class="page-nav-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
                <?php foreach ($sections as $section): ?>
                    <a class="page-nav-link" href="#<?= htmlspecialchars($section['id'], ENT_QUOTES, 'UTF-8') ?>"
                        data-page-nav-link="<?= htmlspecialchars($section['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="page-nav-link-title"><?= htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <?php
    }
}
