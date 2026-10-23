<?php
defined('SACCO') or exit;
$root = str_replace('/', DIRECTORY_SEPARATOR, APP_ROOT);
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Administration</span><i class="bi bi-chevron-right"></i><span>Backups</span></div>
    <h1>Backups</h1>
    <p>Compressed copies of the whole database, kept in <code>storage/backups</code>.</p>
  </div>
  <div class="page-actions"><button class="btn btn-primary" id="backupNow"><i class="bi bi-database-down"></i><span>Back up now</span></button></div>
</div>

<div class="grid-kpi mb-3">
  <?= kpi_card('Backups stored', 'count', 'archive', 'navy', 'num') ?>
  <?= kpi_card('Latest backup', 'latest', 'clock-history', 'green', 'num', '<span data-latest-text></span>') ?>
  <?= kpi_card('Space used', 'size_mb', 'hdd', 'teal', 'num', 'MB') ?>
  <?= kpi_card('Retention', 'keep', 'calendar-range', 'amber', 'num', 'newest copies kept') ?>
</div>

<div class="grid-main">
  <section class="card">
    <div class="card-head"><h3><span class="h-icon"><i class="bi bi-archive"></i></span>Stored backups</h3></div>
    <div class="card-body flush"><div class="table-wrap"><table class="table">
      <thead><tr><th>File</th><th>Created</th><th class="num">Size</th><th></th></tr></thead><tbody data-backups></tbody></table></div></div>
  </section>
  <section class="card">
    <div class="card-head"><h3><span class="h-icon"><i class="bi bi-calendar-check"></i></span>Schedule nightly backups</h3></div>
    <div class="card-body text-sm">
      <p class="mb-2">Windows (Task Scheduler), run once in an administrator prompt:</p>
      <p class="mb-2"><code style="display:block;white-space:pre-wrap;padding:10px">schtasks /create /sc daily /st 01:30 /tn "SaccoPro backup" /tr "C:\xampp\php\php.exe \"<?= e($root) ?>\cron\backup.php\""</code></p>
      <p class="mb-2">cPanel → Cron Jobs (or Linux crontab), daily at 01:30:</p>
      <p class="mb-2"><code style="display:block;white-space:pre-wrap;padding:10px">/usr/local/bin/php <?= e(APP_ROOT) ?>/cron/backup.php >/dev/null 2>&1</code></p>
      <div class="info-strip warning"><i class="bi bi-cloud-arrow-up"></i><span>A backup on the same server does not protect you from disk failure or theft. Copy the files to another location (external drive or cloud storage) regularly.</span></div>
      <p class="muted">Restore: <code>gunzip &lt; FILE.sql.gz | mysql -u USER -p <?= e(config('db.name')) ?></code></p>
    </div>
  </section>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App;
  const size = b => b > 1048576 ? (b / 1048576).toFixed(2) + ' MB' : (b / 1024).toFixed(1) + ' KB';
  async function load() {
    let r;
    try { r = await A.get('backups/list'); } catch (e) { return A.toast(e.message, 'error'); }
    const files = r.data;
    A.fillKpis(document, { count: files.length, latest: files.length, size_mb: Math.round(r.total_size / 1048576 * 100) / 100, keep: r.keep });
    A.$('[data-kpi="latest"]').innerHTML = files.length ? A.timeAgo(files[0].created_at) : 'Never';
    A.$('[data-latest-text]').textContent = files.length ? A.dateTime(files[0].created_at) : 'Back up now to create the first copy';
    A.$('[data-kpi="size_mb"]').textContent = (r.total_size / 1048576).toFixed(2);
    A.$('[data-backups]').innerHTML = files.length ? files.map(f => `<tr><td class="mono text-sm">${A.esc(f.file)}</td><td class="nowrap">${A.dateTime(f.created_at)}</td><td class="num">${size(f.size)}</td>
      <td class="num"><div class="row-actions"><a class="act" href="api.php?r=backups/download&file=${encodeURIComponent(f.file)}" title="Download"><i class="bi bi-download"></i></a><button class="act danger" data-del="${A.esc(f.file)}" title="Delete"><i class="bi bi-trash3"></i></button></div></td></tr>`).join('')
      : '<tr><td colspan="4"><div class="empty"><div class="empty-icon"><i class="bi bi-database-exclamation"></i></div><h4>No backups yet</h4><p>Create one now and schedule nightly backups.</p></div></td></tr>';
  }
  A.$('#backupNow').addEventListener('click', async e => {
    const b = e.currentTarget; b.classList.add('loading'); b.disabled = true;
    try { A.toast((await A.post('backups/run')).message); load(); } catch (err) { A.toast(err.message, 'error'); }
    b.classList.remove('loading'); b.disabled = false;
  });
  document.addEventListener('click', async e => {
    const d = e.target.closest('[data-del]');
    if (!d || !await A.confirm({ title: 'Delete this backup?', message: d.dataset.del, confirmText: 'Delete backup' })) return;
    try { A.toast((await A.post('backups/delete', { file: d.dataset.del })).message); load(); } catch (err) { A.toast(err.message, 'error'); }
  });
  load();
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
