<?php

declare(strict_types=1);

/** @var \OCP\IL10N $l */
?>
<div id="storageusage-folder-settings" class="section storageusage-folder-settings">
    <h2><?php p($l->t('Separate folder values')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Select any number of folders to expose as separate values in the JSON response. Each folder can use its own key and output unit.')); ?>
    </p>

    <div
        id="storageusage-settings-status"
        class="storageusage-status"
        role="status"
        aria-live="polite"
    ></div>

    <div id="storageusage-folder-entries" class="storageusage-entry-list"></div>

    <div class="storageusage-actions">
        <button id="storageusage-add-folder" type="button" class="button">
            <?php p($l->t('Select folder')); ?>
        </button>
        <button id="storageusage-save-folders" type="button" class="button primary">
            <?php p($l->t('Save folder settings')); ?>
        </button>
        <button id="storageusage-open-api" type="button" class="button">
            <?php p($l->t('Open API link')); ?>
        </button>
    </div>

    <dialog
        id="storageusage-folder-browser"
        class="storageusage-dialog"
        aria-labelledby="storageusage-folder-browser-title"
    >
        <div class="storageusage-dialog__content">
            <header class="storageusage-dialog__header">
                <h2 id="storageusage-folder-browser-title">
                    <?php p($l->t('Select folder')); ?>
                </h2>
                <button
                    id="storageusage-browser-close"
                    type="button"
                    class="storageusage-icon-button"
                    aria-label="<?php p($l->t('Close folder selection')); ?>"
                    title="<?php p($l->t('Close')); ?>"
                >
                    <span aria-hidden="true">&times;</span>
                </button>
            </header>

            <nav
                id="storageusage-browser-breadcrumbs"
                class="storageusage-breadcrumbs"
                aria-label="<?php p($l->t('Folder path')); ?>"
            ></nav>

            <div
                id="storageusage-browser-status"
                class="storageusage-browser-status"
                role="status"
                aria-live="polite"
            ></div>

            <div class="storageusage-browser-list-header">
                <h3><?php p($l->t('Available subfolders')); ?></h3>
            </div>
            <ul
                id="storageusage-browser-list"
                class="storageusage-browser-list"
                aria-label="<?php p($l->t('Available subfolders')); ?>"
            ></ul>

            <footer class="storageusage-dialog__footer">
                <button id="storageusage-select-current" type="button" class="button primary">
                    <?php p($l->t('Select current folder')); ?>
                </button>
                <button id="storageusage-browser-cancel" type="button" class="button">
                    <?php p($l->t('Cancel')); ?>
                </button>
            </footer>
        </div>
    </dialog>
</div>
