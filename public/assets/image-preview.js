(function () {
    'use strict';

    const dialog = document.getElementById('staff-image-preview-dialog');
    const previewImage = document.getElementById('staff-image-preview-image');
    const previewTitle = document.getElementById('staff-image-preview-title');
    const closeButton = dialog ? dialog.querySelector('[data-image-preview-close]') : null;

    if (!dialog || !previewImage || typeof dialog.showModal !== 'function') {
        return;
    }

    let lastTrigger = null;

    const imageForTrigger = function (trigger) {
        if (trigger instanceof HTMLImageElement) {
            return trigger;
        }
        return trigger.querySelector('img');
    };

    const titleForTrigger = function (trigger, image) {
        const explicitTitle = String(trigger.dataset.imageTitle || '').trim();
        if (explicitTitle !== '') {
            return explicitTitle;
        }

        const alt = image ? String(image.getAttribute('alt') || '').trim() : '';
        const cleanedAlt = alt.replace(/\s+(?:image|thumbnail)$/i, '').trim();
        return cleanedAlt || 'Image';
    };

    const openPreview = function (trigger) {
        const thumbnail = imageForTrigger(trigger);
        if (!thumbnail) {
            return;
        }

        const source = String(
            trigger.dataset.imageSrc
            || thumbnail.currentSrc
            || thumbnail.getAttribute('src')
            || ''
        ).trim();
        if (source === '') {
            return;
        }

        const title = titleForTrigger(trigger, thumbnail);
        lastTrigger = trigger;
        previewImage.setAttribute('src', source);
        previewImage.setAttribute('alt', 'Full-size image of ' + title);
        if (previewTitle) {
            previewTitle.textContent = title + ' image';
        }

        if (!dialog.open) {
            dialog.showModal();
        }
        document.body.classList.add('app-image-preview-open');
        if (closeButton && typeof closeButton.focus === 'function') {
            closeButton.focus();
        }
    };

    const closePreview = function () {
        if (dialog.open) {
            dialog.close();
        }
    };

    document.addEventListener('click', function (event) {
        if (!(event.target instanceof Element)) {
            return;
        }

        const trigger = event.target.closest('[data-image-preview]');
        if (!trigger) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        openPreview(trigger);
    });

    if (closeButton) {
        closeButton.addEventListener('click', closePreview);
    }

    dialog.addEventListener('click', function (event) {
        const bounds = dialog.getBoundingClientRect();
        const outsideDialog = event.clientX < bounds.left
            || event.clientX > bounds.right
            || event.clientY < bounds.top
            || event.clientY > bounds.bottom;
        if (outsideDialog) {
            closePreview();
        }
    });

    dialog.addEventListener('close', function () {
        document.body.classList.remove('app-image-preview-open');
        previewImage.removeAttribute('src');
        previewImage.setAttribute('alt', '');

        const focusTarget = lastTrigger;
        lastTrigger = null;
        if (focusTarget && focusTarget.isConnected && typeof focusTarget.focus === 'function') {
            focusTarget.focus();
        }
    });
}());
