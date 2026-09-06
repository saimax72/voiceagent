<?php
$labels = array_keys($series);
$maxMsg = max(1, max(array_map(static fn($d) => $d['messages'], $series)));
$maxConv = max(1, max(array_map(static fn($d) => $d['conversations'], $series)));
$n = count($labels);
$w = 100 / max(1, $n);
$points = [];
$i = 0;
foreach ($series as $d) { $points[] = round($i * $w + $w / 2, 2) . ',' . round(34 - ($d['conversations'] / $maxConv) * 30, 2); $i++; }
$voiceShare = $totals['messages'] > 0 ? round($totals['voice_messages'] / $totals['messages'] * 100) : 0;
$devTotal = max(1, array_sum($devices));
$maxHour = max(1, max($hours));
$fbTotal = $feedback['up'] + $feedback['down'];
?>
<div class="page-header">
  <div><h1>Analytics</h1><p>How visitors use your assistant<?= $maxDays > 0 ? ' (your plan keeps ' . (int) $maxDays . ' days of history)' : '' ?>.</p></div>
  <form method="get" class="actions">
    <select class="form-select form-control-sm" name="agent" onchange="this.form.submit()"><option value="">All agents</option><?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $agentId === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
    <div class="segmented"><?php foreach ([7, 30, 90] as $d): ?><button type="submit" name="days" value="<?= $d ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?> days</button><?php endforeach; ?></div>
  </form>
</div>
<div class="grid grid-4 mb-6">
  <div class="card stat"><div class="stat-label">Conversations</div><div class="stat-value"><?= format_number($totals['conversations']) ?></div><div class="stat-delta"><?= number_format($avgMessages, 1) ?> messages per conversation</div></div>
  <div class="card stat"><div class="stat-label">Messages answered</div><div class="stat-value"><?= format_number($totals['messages']) ?></div><div class="stat-delta"><?= $voiceShare ?>% by voice &middot; <?= $avgLatency > 0 ? round($avgLatency / 1000, 1) . 's avg reply' : '' ?></div></div>
  <div class="card stat"><div class="stat-label">Leads captured</div><div class="stat-value"><?= format_number($totals['leads']) ?></div><div class="stat-delta"><?= $totals['conversations'] > 0 ? round($totals['leads'] / $totals['conversations'] * 100, 1) . '% of conversations' : '' ?></div></div>
  <div class="card stat"><div class="stat-label">Unanswered</div><div class="stat-value"><?= format_number($totals['unanswered']) ?></div><div class="stat-delta"><?= $totals['messages'] > 0 ? round(100 - $totals['unanswered'] / $totals['messages'] * 100, 1) . '% answered from knowledge' : '' ?></div></div>
</div>

<div class="grid grid-sidebar">
  <div class="stack">
    <div class="card">
      <div class="card-header"><div><h3>Messages &amp; conversations per day</h3><div class="sub">Bars: messages answered. Line: conversations started.</div></div></div>
      <div class="card-body">
        <svg viewBox="0 0 100 36" preserveAspectRatio="none" style="width:100%;height:200px;display:block" role="img" aria-label="Activity chart">
          <?php $i = 0; foreach ($series as $day => $d): $h = ($d['messages'] / $maxMsg) * 30; ?>
            <rect x="<?= $i * $w + $w * 0.2 ?>" y="<?= 34 - $h ?>" width="<?= $w * 0.6 ?>" height="<?= max(0.4, $h) ?>" rx="0.6" fill="<?= $d['messages'] > 0 ? '#c7d2fe' : '#eef0f6' ?>"><title><?= e($day) ?>: <?= $d['messages'] ?> messages (<?= $d['voice_messages'] ?> voice), <?= $d['conversations'] ?> conversations, <?= $d['leads'] ?> leads</title></rect>
            <?php $vh = ($d['voice_messages'] / $maxMsg) * 30; if ($vh > 0): ?><rect x="<?= $i * $w + $w * 0.2 ?>" y="<?= 34 - $vh ?>" width="<?= $w * 0.6 ?>" height="<?= $vh ?>" rx="0.6" fill="#5b5bd6"></rect><?php endif; ?>
          <?php $i++; endforeach; ?>
          <polyline points="<?= implode(' ', $points) ?>" fill="none" stroke="#10b981" stroke-width="0.6" vector-effect="non-scaling-stroke"></polyline>
        </svg>
        <div class="flex justify-between text-xs text-muted mt-2"><span><?= e(format_date($labels[0] . ' 00:00:00', 'M j')) ?></span><span class="flex gap-3"><span><span style="display:inline-block;width:10px;height:10px;background:#5b5bd6;border-radius:2px"></span> voice</span><span><span style="display:inline-block;width:10px;height:10px;background:#c7d2fe;border-radius:2px"></span> text</span><span><span style="display:inline-block;width:10px;height:3px;background:#10b981"></span> conversations</span></span><span>Today</span></div>
      </div>
    </div>
    <div class="grid grid-2">
      <div class="card"><div class="card-header"><h3>Most asked</h3></div>
        <?php if (!$topQuestions): ?><div class="empty" style="padding:24px"><p class="mb-0">No questions yet.</p></div><?php else: ?>
        <div class="table-wrap"><table class="table table-compact"><tbody><?php foreach ($topQuestions as $q): ?><tr><td class="text-sm"><?= e(str_limit($q['q'], 70)) ?></td><td class="text-right muted"><?= (int) $q['n'] ?>x</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
      </div>
      <div class="card"><div class="card-header"><h3>Busiest hours (UTC)</h3></div><div class="card-body">
        <svg viewBox="0 0 96 30" preserveAspectRatio="none" style="width:100%;height:120px;display:block"><?php foreach ($hours as $h => $count): $bh = ($count / $maxHour) * 26; ?><rect x="<?= $h * 4 + 0.5 ?>" y="<?= 28 - $bh ?>" width="3" height="<?= max(0.4, $bh) ?>" rx="0.5" fill="<?= $count > 0 ? '#8b5cf6' : '#eef0f6' ?>"><title><?= $h ?>:00 - <?= $count ?> messages</title></rect><?php endforeach; ?></svg>
        <div class="flex justify-between text-xs text-muted mt-1"><span>0h</span><span>6h</span><span>12h</span><span>18h</span><span>24h</span></div>
      </div></div>
    </div>
  </div>
  <div class="stack">
    <div class="card"><div class="card-header"><h3>Devices</h3></div><div class="card-body">
      <?php foreach (['desktop' => 'Desktop', 'mobile' => 'Mobile', 'tablet' => 'Tablet', 'unknown' => 'Unknown'] as $k => $l): $v = (int) ($devices[$k] ?? 0); if ($v === 0 && $k === 'unknown') continue; ?>
        <div class="flex items-center gap-3 mb-2"><span style="width:70px" class="text-sm"><?= $l ?></span><div class="meter flex-1"><span style="width:<?= round($v / $devTotal * 100) ?>%"></span></div><span class="text-sm text-muted" style="width:44px;text-align:right"><?= round($v / $devTotal * 100) ?>%</span></div>
      <?php endforeach; ?>
    </div></div>
    <div class="card"><div class="card-header"><h3>Visitor feedback</h3></div><div class="card-body">
      <?php if ($fbTotal === 0): ?><p class="text-muted mb-0">No ratings yet. Visitors can rate every answer with a thumbs up or down.</p><?php else: ?>
      <div class="stat-value" style="font-size:30px"><?= round($feedback['up'] / $fbTotal * 100) ?>%<span class="text-sm text-muted" style="font-weight:500"> helpful</span></div>
      <div class="text-sm text-muted mt-1"><?= $feedback['up'] ?> positive &middot; <?= $feedback['down'] ?> negative</div>
      <?php endif; ?>
    </div></div>
    <div class="card"><div class="card-header"><h3>Top pages</h3></div>
      <?php if (!$topPages): ?><div class="empty" style="padding:24px"><p class="mb-0">Pages where conversations start will appear here.</p></div><?php else: ?>
      <div class="table-wrap"><table class="table table-compact"><tbody><?php foreach ($topPages as $p): ?><tr><td class="text-sm truncate" style="max-width:220px"><?= e((string) (parse_url($p['page_url'], PHP_URL_PATH) ?: '/')) ?></td><td class="text-right muted"><?= (int) $p['n'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="card"><div class="card-header"><h3>This month's usage</h3></div><div class="card-body">
      <?php $limit = (int) ($plan['limits']['messages_per_month'] ?? 0); $used = (int) $usage['messages']; ?>
      <div class="flex justify-between text-sm mb-1"><span>Messages</span><span><?= format_number($used) ?> / <?= $limit > 0 ? format_number($limit) : 'unlimited' ?></span></div>
      <div class="meter<?= $limit > 0 && $used / $limit > .9 ? ' warn' : '' ?>"><span style="width:<?= $limit > 0 ? min(100, round($used / $limit * 100)) : 0 ?>%"></span></div>
      <div class="text-xs text-muted mt-3">Voice: <?= format_number((int) $usage['voice_messages']) ?> messages &middot; <?= format_number((int) $usage['tts_characters']) ?> spoken characters &middot; <?= format_number((int) round($usage['stt_seconds'] / 60)) ?> min transcribed</div>
    </div></div>
  </div>
</div>
