<?php
/**
 * Shared "Knowledge base" form fields. The enclosing <form> must use enctype="multipart/form-data".
 * Optional: $showWebsite (bool), $websiteValue (string), $limits (['documents' => int, 'documents_used' => int]).
 */
$showWebsite = $showWebsite ?? true;
$websiteValue = $websiteValue ?? '';
$docLimit = (int) ($limits['documents'] ?? 0);
$docUsed = (int) ($limits['documents_used'] ?? 0);
$extensions = \App\Services\Knowledge\DocumentExtractor::ALLOWED_EXTENSIONS;
?>
<div x-data="{ ktab: '<?= $showWebsite ? 'website' : 'files' ?>', faqs: [{q:'', a:''}], fileNames: [] }">
  <div class="segmented mb-4" style="display:flex;flex-wrap:wrap">
    <?php if ($showWebsite): ?><button type="button" :class="{active: ktab==='website'}" @click="ktab='website'">Website</button><?php endif; ?>
    <button type="button" :class="{active: ktab==='files'}" @click="ktab='files'">Documents<template x-if="fileNames.length"><span class="badge badge-primary" style="margin-left:6px" x-text="fileNames.length"></span></template></button>
    <button type="button" :class="{active: ktab==='faq'}" @click="ktab='faq'">FAQs</button>
    <button type="button" :class="{active: ktab==='text'}" @click="ktab='text'">Custom information</button>
    <button type="button" :class="{active: ktab==='pages'}" @click="ktab='pages'">Specific pages</button>
  </div>

  <?php if ($showWebsite): ?>
  <div x-show="ktab==='website'">
    <div class="form-group">
      <label class="form-label">Website to learn from</label>
      <input class="form-control" name="website_url" value="<?= e($websiteValue) ?>" placeholder="https://www.example.com">
      <label class="checkbox mt-2"><input type="checkbox" name="scan" value="1" checked> <span>Scan the website right away (pages, sitemap and linked PDFs)</span></label>
    </div>
  </div>
  <?php endif; ?>

  <div x-show="ktab==='files'" <?= $showWebsite ? 'x-cloak' : '' ?>>
    <div class="form-group">
      <label class="form-label">Upload documents</label>
      <input class="form-control" type="file" name="files[]" multiple accept=".<?= implode(',.', $extensions) ?>" @change="fileNames = Array.from($event.target.files).map(f => f.name)">
      <div class="form-hint">PDF, Word, PowerPoint, text, Markdown, CSV or HTML, up to <?= e(human_filesize(\App\Services\Knowledge\DocumentExtractor::MAX_UPLOAD_BYTES)) ?> each. You can select several files at once.<?= $docLimit > 0 ? ' Your plan includes ' . $docLimit . ' documents per agent' . ($docUsed > 0 ? ' (' . $docUsed . ' used)' : '') . '.' : '' ?></div>
      <ul class="mt-2 text-sm text-muted" style="margin:8px 0 0;padding-left:18px" x-show="fileNames.length"><template x-for="n in fileNames"><li x-text="n"></li></template></ul>
    </div>
  </div>

  <div x-show="ktab==='faq'" x-cloak>
    <div class="form-hint mb-3">Add the questions visitors ask most, with the exact answer you want given.</div>
    <template x-for="(f, i) in faqs" :key="i">
      <div class="form-group" style="padding:12px;border:1px solid var(--border);border-radius:8px">
        <input class="form-control mb-2" name="faq_question[]" placeholder="Question, e.g. Do you offer free delivery?" x-model="f.q">
        <textarea class="form-control" name="faq_answer[]" rows="2" placeholder="Answer" x-model="f.a"></textarea>
        <button type="button" class="btn btn-ghost btn-sm mt-2" x-show="faqs.length > 1" @click="faqs.splice(i, 1)">Remove</button>
      </div>
    </template>
    <button type="button" class="btn btn-secondary btn-sm" @click="faqs.push({q:'', a:''})">+ Add another question</button>
  </div>

  <div x-show="ktab==='text'" x-cloak>
    <div class="form-group"><label class="form-label">Title <span class="optional">(optional)</span></label><input class="form-control" name="info_title" placeholder="Opening hours, prices, policies..."></div>
    <div class="form-group"><label class="form-label">Information</label><textarea class="form-control" name="info_content" rows="7" placeholder="Write or paste anything the assistant should know: services, prices, opening hours, return policy, contact details..."></textarea></div>
  </div>

  <div x-show="ktab==='pages'" x-cloak>
    <div class="form-group"><label class="form-label">Page addresses (one per line)</label><textarea class="form-control" name="extra_urls" rows="4" placeholder="https://www.example.com/pricing&#10;https://www.example.com/brochure.pdf"></textarea><div class="form-hint">Adds individual pages or online PDFs without crawling a whole site. Up to 10 at a time.</div></div>
  </div>
</div>
