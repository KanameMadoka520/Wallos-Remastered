<?php
require_once 'includes/header.php';
require_once 'includes/agent_i18n.php';
$agentLabels = wallos_agent_labels($lang);
function agent_escape($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<link rel="stylesheet" href="styles/agent.css?v=<?= filemtime(__DIR__ . '/styles/agent.css') ?>">
<section class="contain settings agent-settings" data-agent-settings>
    <h1><?= agent_escape($agentLabels['title']) ?></h1>
    <p><?= agent_escape($agentLabels['intro']) ?></p>
    <section class="account-section">
        <p><strong><?= agent_escape($agentLabels['endpoint']) ?></strong><br><code data-agent-mcp>/api/agent/mcp.php</code></p>
        <p><strong><?= agent_escape($agentLabels['http']) ?></strong><br><code data-agent-http>/api/agent/v1/index.php</code></p>
        <p><?= agent_escape($agentLabels['client']) ?></p>
        <p><?= agent_escape($agentLabels['hint']) ?></p>
        <p><?= agent_escape($agentLabels['readme']) ?></p>
    </section>
    <section class="account-section">
        <h2><?= agent_escape($agentLabels['create']) ?></h2>
        <form data-agent-create autocomplete="off">
            <label><?= agent_escape($agentLabels['name']) ?><input type="text" name="name" required maxlength="100" autocomplete="off"></label>
            <label><?= agent_escape($agentLabels['days']) ?><input name="days" type="number" min="1" max="365" value="30" required></label>
            <fieldset><legend><?= agent_escape($agentLabels['scope']) ?></legend>
                <?php foreach (['subscriptions', 'payments', 'references', 'budgets'] as $resource): ?>
                <div class="agent-scope"><strong><?= agent_escape($agentLabels[$resource]) ?></strong>
                    <?php foreach (['read', 'write', 'delete'] as $verb): if ($resource === 'budgets' && $verb === 'delete') continue; ?>
                    <label><input type="checkbox" name="scopes[]" value="<?= $resource . ':' . $verb ?>" <?= $verb === 'read' ? 'checked' : '' ?>><?= agent_escape($agentLabels[$verb]) ?></label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <label><input type="checkbox" name="scopes[]" value="audit:read"><?= agent_escape($agentLabels['audit_read']) ?></label>
            </fieldset>
            <button type="submit" class="button"><?= agent_escape($agentLabels['create']) ?></button>
        </form>
        <div data-agent-secret-panel hidden>
            <p><?= agent_escape($agentLabels['once']) ?></p>
            <input type="text" data-agent-secret readonly aria-label="Agent key" autocomplete="off" spellcheck="false">
            <button type="button" class="button thin" data-agent-copy><?= agent_escape($agentLabels['copy']) ?></button>
            <button type="button" class="button thin" data-agent-hide><?= agent_escape($agentLabels['hide']) ?></button>
        </div>
        <p data-agent-feedback role="status" aria-live="polite"></p>
    </section>
    <section class="account-section"><h2><?= agent_escape($agentLabels['keys']) ?></h2><div data-agent-keys><?= agent_escape($agentLabels['loading']) ?></div></section>
    <section class="account-section"><h2><?= agent_escape($agentLabels['audit']) ?></h2><div data-agent-audit><?= agent_escape($agentLabels['loading']) ?></div></section>
</section>
<script type="application/json" id="agent-labels"><?= json_encode($agentLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script defer src="scripts/agent.js?v=<?= filemtime(__DIR__ . '/scripts/agent.js') ?>"></script>
<?php require_once 'includes/footer.php'; ?>
