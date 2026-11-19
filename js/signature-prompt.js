(() => {
    'use strict';

    const scriptUrl = document.currentScript ? document.currentScript.src : window.location.href;
    const endpointUrl = new URL('../front/signature-status.php', scriptUrl);
    const signatureUrl = new URL('../front/my-signature.php?welcome=1', scriptUrl);
    const menuPath = '/plugins/informeglpi/front/my-signature.php';

    function moveSignatureShortcut() {
        const link = Array.from(document.querySelectorAll('a[href]')).find((item) => {
            try {
                return new URL(item.href, window.location.origin).pathname.endsWith(menuPath);
            } catch (error) {
                return false;
            }
        });

        if (!link) return;

        link.href = signatureUrl.href;
        link.innerHTML = '<i class="ti ti-signature" aria-hidden="true"></i><span> Mi firma personal</span>';
        link.setAttribute('aria-label', 'Mi firma personal');
        link.setAttribute('title', 'Mi firma personal');

        // GLPI places plugin links inside the "Complementos" dropdown.
        // Move this one link beside the standard Helpdesk navigation items.
        const item = link.closest('li') || link.parentElement;
        const homeLink = Array.from(document.querySelectorAll('a[href]')).find((item) => {
            try {
                return /\\/Helpdesk\\/?$/i.test(new URL(item.href, window.location.origin).pathname);
            } catch (error) {
                return false;
            }
        });
        const navItem = homeLink ? (homeLink.closest('li') || homeLink.parentElement) : null;
        const navList = navItem ? navItem.parentElement : null;

        if (item && navList && item.parentElement !== navList) {
            navList.insertBefore(item, navItem.nextSibling);
            item.classList.add('informeglpi-signature-nav-item');
        }
    }

    function buildDialog() {
        if (document.getElementById('informeglpi-signature-dialog')) return null;

        const dialog = document.createElement('dialog');
        dialog.id = 'informeglpi-signature-dialog';
        dialog.className = 'informeglpi-signature-dialog';
        dialog.setAttribute('aria-labelledby', 'informeglpi-signature-title');
        dialog.innerHTML = `
            <div class="informeglpi-signature-card">
                <div class="informeglpi-signature-brand">
                    <span class="informeglpi-signature-brand-icon" aria-hidden="true"><i class="ti ti-shield-check"></i></span>
                    <span>SONGA <small>GESTIÓN DE IDENTIDAD</small></span>
                </div>
                <div class="informeglpi-signature-content">
                    <div class="informeglpi-signature-symbol" aria-hidden="true"><i class="ti ti-file-pencil"></i></div>
                    <span class="informeglpi-signature-eyebrow">ACCIÓN PENDIENTE</span>
                    <h2 id="informeglpi-signature-title">Registra tu firma personal</h2>
                    <p class="informeglpi-signature-lead">Aún no tienes una firma registrada en GLPI.</p>
                    <p class="informeglpi-signature-description">Configúrala para utilizarla cuando recibas activos tecnológicos y debas autorizar actas de entrega.</p>
                    <ul class="informeglpi-signature-benefits">
                        <li><i class="ti ti-signature" aria-hidden="true"></i><span>Registra tu firma manuscrita</span></li>
                        <li><i class="ti ti-lock" aria-hidden="true"></i><span>Protege su uso con un PIN personal</span></li>
                        <li><i class="ti ti-user-check" aria-hidden="true"></i><span>Queda asociada a tu usuario</span></li>
                    </ul>
                    <div class="informeglpi-signature-actions">
                        <button type="button" class="btn btn-primary informeglpi-signature-create" data-action="create"><i class="ti ti-edit" aria-hidden="true"></i> Crear mi firma personal</button>
                        <button type="button" class="btn btn-outline-secondary informeglpi-signature-later" data-action="later">Recordármelo después</button>
                    </div>
                    <p class="informeglpi-signature-footnote"><i class="ti ti-shield-lock" aria-hidden="true"></i> Tu firma y tu PIN son personales. No compartas tus credenciales.</p>
                </div>
            </div>`;

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
        dialog.querySelector('[data-action="create"]').addEventListener('click', () => window.location.assign(signatureUrl.href));
        dialog.querySelector('[data-action="later"]').addEventListener('click', () => dialog.close());
        document.body.appendChild(dialog);
        return dialog;
    }

    async function showPendingSignaturePrompt() {
        if (!/\\/(?:Helpdesk|ServiceCatalog|front\\/helpdesk\\.public\\.php)\\/?$/i.test(window.location.pathname)) return;

        moveSignatureShortcut();

        try {
            const response = await fetch(endpointUrl.href, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) return;
            const result = await response.json();
            if (!result.pending || !document.body) return;

            const dialog = buildDialog();
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
                dialog.querySelector('[data-action="create"]').focus();
            }
        } catch (error) {
            // Keep the Helpdesk available if the optional prompt cannot be loaded.
        }
    }

    function start() {
        moveSignatureShortcut();
        showPendingSignaturePrompt();
        // Helpdesk navigation can be rendered asynchronously by GLPI.
        const observer = new MutationObserver(() => moveSignatureShortcut());
        observer.observe(document.body, { childList: true, subtree: true });
        window.setTimeout(() => observer.disconnect(), 10000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
