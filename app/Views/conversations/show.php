<div class="breadcrumb"><a href="<?= e(url('/conversations')) ?>">Conversations</a> <span>/</span> <span><?= e(str_limit($conversation['title'] ?: 'Conversation', 60)) ?></span></div>
<div class="page-header" style="margin-top:0">
  <div><h1 style="font-size:20px"><?= e($conversation['title'] ?: 'Conversation') ?></h1><p><?= e($conversation['agent_name']) ?> &middot; <?= e(format_date($conversation['started_at'], 'M j, Y H:i')) ?> &middot; <?= (int) $conversation['message_count'] ?> messages<?= $conversation['is_test'] ? ' &middot; <span class="badge badge-info">Test</span>' : '' ?></p></div>
  <div class="actions"><form method="post" action="<?= e(url('/conversations/' . $conversation['id'] . '/delete')) ?>" data-confirm="Delete this conversation?"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Delete</button></form></div>
</div>
<div class="grid grid-sidebar">
  <div class="card"><div class="card-body">
    <div class="transcript">
      <?php foreach ($messages as $m): ?>
        <?php if ($m['role'] === 'system'): ?>
          <div class="text-center text-xs text-muted" style="padding:4px"><?= e($m['content']) ?></div>
          <?php continue; endif; ?>
        <div class="msg <?= $m['role'] === 'user' ? 'user' : 'bot' ?>">
          <div>
            <div class="bubble"><?= nl2br(e($m['content'])) ?></div>
            <div class="meta">
              <?php if ($m['modality'] === 'voice'): ?><span class="badge badge-primary" style="padding:1px 7px">voice</span><?php endif; ?>
              <span><?= e(format_date($m['created_at'], 'H:i:s')) ?></span>
              <?php if ($m['role'] === 'assistant'): ?>
                <?php if ((int) $m['latency_ms'] > 0): ?><span><?= round($m['latency_ms'] / 1000, 1) ?>s</span><?php endif; ?>
                <?php if ((int) $m['tokens_input'] > 0): ?><span title="tokens in / out"><?= (int) $m['tokens_input'] ?>/<?= (int) $m['tokens_output'] ?> tok</span><?php endif; ?>
                <?php if (!empty($m['meta']['model'])): ?><span><?= e($m['meta']['model']) ?></span><?php endif; ?>
                <?php if ($m['feedback'] === 1 || $m['feedback'] === '1'): ?><span class="text-success">&#128077; helpful</span><?php elseif ($m['feedback'] === -1 || $m['feedback'] === '-1'): ?><span class="text-danger">&#128078; not helpful</span><?php endif; ?>
                <?php if ((int) $m['is_unanswered']): ?><span class="badge badge-warning" style="padding:1px 7px">unanswered</span><?php endif; ?>
              <?php endif; ?>
            </div>
            <?php if ($m['role'] === 'assistant' && $m['sources']): ?><div class="sources"><?php foreach ($m['sources'] as $s): ?><span title="score <?= e((string) ($s['score'] ?? '')) ?>"><?= e(str_limit($s['title'] ?? 'Source', 40)) ?></span><?php endforeach; ?></div><?php endif; ?>
            <?php if ($m['role'] === 'assistant' && !empty($m['meta']['tools'])): ?><div class="sources"><?php foreach ($m['meta']['tools'] as $t): ?><span class="badge badge-info"><?= e($t['name'] === 'save_lead' ? 'Lead saved' : 'Question logged') ?></span><?php endforeach; ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div></div>
  <div class="stack">
    <div class="card"><div class="card-header"><h3>Visitor</h3></div><div class="card-body">
      <dl class="kv">
        <dt>Device</dt><dd><?= e(ucfirst($conversation['device'] ?: 'unknown')) ?></dd>
        <dt>Page</dt><dd><?= $conversation['page_url'] ? '<a href="' . e($conversation['page_url']) . '" target="_blank" rel="noopener">' . e(str_limit($conversation['page_url'], 40)) . '</a>' : '-' ?></dd>
        <dt>Referrer</dt><dd><?= e($conversation['referrer'] ? str_limit($conversation['referrer'], 40) : 'Direct') ?></dd>
        <dt>Visitor ID</dt><dd class="mono text-xs"><?= e($conversation['visitor_id']) ?></dd>
        <dt>Channel</dt><dd><?= e(ucfirst($conversation['channel'])) ?></dd>
        <dt>Status</dt><dd><?= e(ucfirst($conversation['status'])) ?></dd>
      </dl>
    </div></div>
    <?php if ($lead): ?>
    <div class="card"><div class="card-header"><h3>Lead captured</h3><div class="actions"><a class="text-sm" href="<?= e(url('/leads')) ?>">Manage</a></div></div><div class="card-body">
      <dl class="kv"><dt>Name</dt><dd><?= e($lead['name'] ?: '-') ?></dd><dt>Email</dt><dd><?= $lead['email'] ? '<a href="mailto:' . e($lead['email']) . '">' . e($lead['email']) . '</a>' : '-' ?></dd><dt>Phone</dt><dd><?= e($lead['phone'] ?: '-') ?></dd><dt>Message</dt><dd><?= e($lead['message'] ?: '-') ?></dd><dt>Status</dt><dd><span class="badge badge-<?= $lead['status'] === 'new' ? 'warning' : 'neutral' ?>"><?= e(ucfirst($lead['status'])) ?></span></dd></dl>
    </div></div>
    <?php endif; ?>
    <?php if ($unanswered): ?>
    <div class="card"><div class="card-header"><h3>Unanswered here</h3><div class="actions"><a class="text-sm" href="<?= e(url('/unanswered')) ?>">Teach</a></div></div><div class="card-body">
      <?php foreach ($unanswered as $u): ?><div class="mb-2"><span class="badge badge-<?= $u['status'] === 'open' ? 'warning' : 'success' ?>"><?= e($u['status']) ?></span> <?= e($u['question']) ?></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>
