<?php
require_once 'includes/header.php';
require_once 'includes/page_navigation.php';

$wallosIsUpToDate = true;
if (!is_null($settings['latest_version'])) {
    $latestVersion = $settings['latest_version'];
    if (version_compare($version, $latestVersion) == -1) {
        $wallosIsUpToDate = false;
    }
}

$pageSections = [
    ['id' => 'about-remastered', 'label' => 'Wallos-Remastered'],
    ['id' => 'about-upstream', 'label' => translate('about', $i18n)],
    ['id' => 'about-credits', 'label' => translate('credits', $i18n)],
];
?>

<section class="contain has-page-nav">
    <div class="page-layout">
        <?php render_page_navigation(translate('about', $i18n), $pageSections); ?>
        <div class="page-content">

            <section class="account-section" id="about-remastered" data-page-section>
                <header>
                    <h2>Wallos-Remastered</h2>
                </header>
                <div class="credits-list">
                    <div>
                        <h3>Project Positioning</h3>
                        <span>An independently maintained remastered branch focused on admin operations, media governance, and self-host lifecycle management.</span>
                    </div>
                    <div>
                        <h3>Repository</h3>
                        <span>
                            https://github.com/KanameMadoka520/Wallos-Remastered
                            <a href="https://github.com/KanameMadoka520/Wallos-Remastered" target="_blank"
                                title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </span>
                    </div>
                    <div>
                        <h3>License</h3>
                        <span>GPLv3</span>
                    </div>
                </div>
            </section>

            <section class="account-section" id="about-upstream" data-page-section>
                <header>
                    <h2><?= translate('about', $i18n) ?></h2>
                </header>
                <div class="credits-list">
                    <div>
                        <h3>
                            Wallos-Remastered <?= $version ?> <?= $demoMode ? "Demo" : "" ?>
                        </h3>
                        <span>Current running version of the remastered build.</span>
                    </div>
                    <?php if (!$wallosIsUpToDate): ?>
                        <div class="update-available">
                            <h3>
                                <i class="fa-solid fa-info-circle"></i>
                                <?= translate('update_available', $i18n) ?> <?= $latestVersion ?>
                            </h3>
                            <span>A newer tracked upstream version is available for manual review.</span>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h3>Upstream</h3>
                        <span>
                            Wallos
                            <a href="https://github.com/ellite/Wallos" target="_blank"
                                title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </span>
                    </div>
                    <div>
                        <h3><?= translate('issues_and_requests', $i18n) ?></h3>
                        <span>
                            GitHub
                            <a href="https://github.com/KanameMadoka520/Wallos-Remastered/issues" target="_blank"
                                title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </span>
                    </div>
                    <div>
                        <h3>Original Author</h3>
                        <span>
                            https://henrique.pt
                            <a href="https://henrique.pt/" target="_blank"
                                title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </span>
                    </div>
                    <div>
                        <h3>Remastered Author</h3>
                        <span>
                            https://github.com/KanameMadoka520
                            <a href="https://github.com/KanameMadoka520" target="_blank"
                                title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </span>
                    </div>
                </div>
            </section>

            <section class="account-section" id="about-credits" data-page-section>
                <header>
                    <h2><?= translate("credits", $i18n) ?></h2>
                </header>
                <div class="credits-list">
                    <div>
                        <h3>Wallos Original Project</h3>
                        <span>
                            https://github.com/ellite/Wallos
                            <a href="https://github.com/ellite/Wallos" target="_blank"
                                title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </span>
                    </div>
                    <div>
                        <h3><?= translate('icons', $i18n) ?></h3>
                        <span>
                            https://www.streamlinehq.com/freebies/plump-flat-free
                            <a href="https://www.streamlinehq.com/freebies/plump-flat-free" target="_blank"
                                title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </span>
                    </div>
                    <div>
                        <h3><?= translate('payment_icons', $i18n) ?></h3>
                        <span>
                            https://www.figma.com/file/5IMW8JfoXfB5GRlPNdTyeg/Credit-Cards-and-Payment-Methods-Icons-(Community)
                            <a href="https://www.figma.com/file/5IMW8JfoXfB5GRlPNdTyeg/Credit-Cards-and-Payment-Methods-Icons-(Community)"
                                target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </span>
                    </div>
                    <div>
                        <h3>Chart.js</h3>
                        <span>
                            https://www.chartjs.org/
                            <a href="https://www.chartjs.org/" target="_blank" title="<?= translate('external_url', $i18n) ?>"
                                rel="noreferrer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </span>
                    </div>
                    <div>
                        <h3>QRCode.js</h3>
                        <span>
                            https://github.com/davidshimjs/qrcodejs
                            <a href="https://github.com/davidshimjs/qrcodejs" target="_blank"
                                title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </span>
                    </div>
                </div>
            </section>

        </div>
    </div>
</section>

<?php
require_once 'includes/footer.php';
?>
