<?php
/** @var array $teams  @var array $sites  @var int|null $siteId */
$shiftIcons = ['day' => 'sun', 'night' => 'moon', 'rotation' => 'repeat'];
$bySite = [];
foreach ($teams as $team) {
    $bySite[$team['site']][] = $team;
}
$canManage = $currentUser->can('personnel.manage');
$initials = static fn (string $name) => mb_strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2))));
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="users-round"></i></span>
        <div>
            <h1><?= e(t('nav.teams')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('teams.subtitle', ['count' => count($teams)])) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/personnel', $siteId ? ['site_id' => $siteId] : [])) ?>"><i data-lucide="contact"></i><?= e(t('nav.personnel')) ?></a>
        <?php if ($canManage): ?>
            <a class="btn btn--primary" href="<?= e(url('/teams/create', $siteId ? ['site_id' => $siteId] : [])) ?>"><i data-lucide="plus"></i><?= e(t('teams.new')) ?></a>
        <?php endif; ?>
    </div>
</div>

<nav class="chips" aria-label="<?= e(t('fields.site_id')) ?>">
    <a class="chip-btn" aria-pressed="<?= $siteId === null ? 'true' : 'false' ?>" href="<?= e(url('/teams')) ?>"><?= e(t('common.all')) ?></a>
    <?php foreach ($sites as $site): ?>
        <a class="chip-btn" aria-pressed="<?= $siteId === (int) $site['id'] ? 'true' : 'false' ?>" href="<?= e(url('/teams', ['site_id' => $site['id']])) ?>"><?= e($site['name']) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($teams === []): ?>
    <section class="card">
        <div class="empty-state">
            <i data-lucide="users-round"></i>
            <p><?= e(t('teams.none')) ?></p>
            <?php if ($canManage): ?><a class="btn btn--primary btn--sm" href="<?= e(url('/teams/create', $siteId ? ['site_id' => $siteId] : [])) ?>"><i data-lucide="plus"></i><?= e(t('teams.new')) ?></a><?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php foreach ($bySite as $siteName => $siteTeams): ?>
    <h2 class="section-title"><i data-lucide="map-pin"></i><?= e($siteName) ?></h2>
    <div class="team-grid">
        <?php foreach ($siteTeams as $team): ?>
            <article class="team-card team-card--<?= e($team['shift']) ?>">
                <header class="team-card__head">
                    <div>
                        <h3><?= e($team['name']) ?></h3>
                        <div class="team-card__tags">
                            <span class="shift-badge shift-badge--<?= e($team['shift']) ?>"><i data-lucide="<?= e($shiftIcons[$team['shift']]) ?>"></i><?= e(t('shift.' . $team['shift'])) ?></span>
                            <span class="chip"><?= e(t('team_kind.' . $team['kind'])) ?></span>
                            <span class="muted small"><?= e(t('teams.member_count', ['count' => $team['member_count']])) ?></span>
                        </div>
                    </div>
                    <?php if ($canManage): ?>
                        <a class="icon-btn" href="<?= e(url('/teams/' . $team['id'] . '/edit')) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><i data-lucide="pencil"></i></a>
                    <?php endif; ?>
                </header>

                <div class="contact-row">
                    <div class="contact">
                        <span class="contact__label"><?= e(t('fields.leader_id')) ?></span>
                        <strong><?= e($team['leader'] ?? '—') ?></strong>
                        <?php if ($team['leader_phone']): ?>
                            <a class="phone-btn" href="tel:<?= e(preg_replace('/[^\d+]/', '', $team['leader_phone'])) ?>"><i data-lucide="phone"></i><?= e($team['leader_phone']) ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="contact contact--dispatch">
                        <span class="contact__label"><?= e(t('teams.dispatch')) ?></span>
                        <strong><?= e($team['dispatch_name'] ?? '—') ?></strong>
                        <?php if ($team['dispatch_phone']): ?>
                            <a class="phone-btn phone-btn--strong" href="tel:<?= e(preg_replace('/[^\d+]/', '', $team['dispatch_phone'])) ?>"><i data-lucide="radio"></i><?= e($team['dispatch_phone']) ?></a>
                        <?php endif; ?>
                    </div>
                </div>

                <ul class="member-list">
                    <?php foreach ($team['members'] as $member): ?>
                        <li>
                            <span class="avatar avatar--sm" aria-hidden="true"><?= e($initials($member['name'])) ?></span>
                            <span class="member-list__name"><?= e($member['name']) ?><small><?= e(t('job.' . $member['job'])) ?><?= $member['employee_no'] ? ' · ' . e($member['employee_no']) : '' ?></small></span>
                            <?php if ($member['phone']): ?>
                                <a class="icon-btn" href="tel:<?= e(preg_replace('/[^\d+]/', '', $member['phone'])) ?>" title="<?= e($member['phone']) ?>" aria-label="<?= e(t('teams.call', ['name' => $member['name']])) ?>"><i data-lucide="phone"></i></a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($team['members'] === []): ?><li class="muted small"><?= e(t('teams.no_member')) ?></li><?php endif; ?>
                </ul>
                <?php if ($canManage): ?>
                    <a class="team-card__add" href="<?= e(url('/personnel/create', ['site_id' => $team['site_id'], 'team_id' => $team['id'], 'job' => $team['kind'] === 'maintenance' ? 'mechanic' : 'driver', 'back' => 'teams'])) ?>"><i data-lucide="user-plus"></i><?= e(t('teams.add_member')) ?></a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
