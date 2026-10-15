<?php
/**
 * Champs d'un document d'engin (formulaire de la page Documents et fenêtres de la fiche engin).
 * Le formulaire parent doit être en enctype="multipart/form-data".
 * @var array $doc  document existant (correction) ou valeurs par défaut
 * @var string|null $fileUrl  lien du fichier joint actuel
 */
$fileUrl ??= null;
$val = static fn (string $name) => old($name, $doc[$name] ?? null);
$err = static fn (string $name) => ($m = field_error($name)) ? '<span class="error">' . e($m) . '</span>' : '';
?>
<div class="field">
    <label><?= e(t('fields.issuer')) ?></label>
    <input name="issuer" class="input" maxlength="120" value="<?= e($val('issuer')) ?>">
</div>
<div class="field">
    <label><?= e(t('fields.document_reference')) ?></label>
    <input name="reference" class="input" maxlength="80" value="<?= e($val('reference')) ?>">
</div>
<div class="field">
    <label><?= e(t('fields.issued_at')) ?></label>
    <input name="issued_at" type="date" class="input" value="<?= e($val('issued_at')) ?>">
</div>
<div class="field">
    <label class="required"><?= e(t('fields.expires_at')) ?></label>
    <input name="expires_at" type="date" class="input" required value="<?= e($val('expires_at')) ?>">
    <?= $err('expires_at') ?>
</div>
<div class="field span-2">
    <label><?= e(t('fields.note')) ?></label>
    <input name="note" class="input" maxlength="255" value="<?= e($val('note')) ?>">
</div>
<div class="field span-2">
    <label><?= e(t('fields.document_file')) ?> <span class="muted small">(<?= e(t('common.optional')) ?>)</span></label>
    <?php if ($fileUrl): ?>
        <div class="doc-file">
            <a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener"><i data-lucide="paperclip"></i><?= e($doc['original_name'] ?: t('documents.current_file')) ?></a>
            <label class="check"><input type="checkbox" name="remove_file" value="1"> <?= e(t('documents.remove_file')) ?></label>
        </div>
    <?php endif; ?>
    <input name="document_file" type="file" class="input" accept="application/pdf,image/jpeg,image/png,image/webp">
    <span class="hint"><?= e(t('documents.file_hint', ['max' => \App\Services\EquipmentDocumentService::MAX_BYTES / 1024 / 1024])) ?></span>
    <?= $err('document_file') ?>
</div>
