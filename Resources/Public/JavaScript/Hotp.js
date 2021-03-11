/**
 * Module: TYPO3/CMS/MfaHotp/Hotp
 */
define([
    'TYPO3/CMS/Core/DocumentService',
    'TYPO3/CMS/Core/Event/RegularEvent',
    'TYPO3/CMS/Backend/Modal',
    'TYPO3/CMS/Backend/Enum/Severity',
    'TYPO3/CMS/Core/SecurityUtility'
], function(DocumentService, RegularEvent, Modal, Severity, SecurityUtility) {
    'use strict';

    const Hotp = {
        selectors: {
            authUrlButton: '.t3js-hotp-auth-url-button',
            modalBody: '.t3js-modal-body'
        }
    };

    DocumentService.ready().then((document) => {
        new RegularEvent('click', function (e) {
            e.preventDefault();
            const button = document.querySelector(Hotp.selectors.authUrlButton);
            const $modal = Modal.show(
                button.dataset.title,
                button.dataset.description,
                Severity.info,
                [
                    {
                        text: button.dataset.ok || 'OK',
                        active: true,
                        btnClass: 'btn-default',
                        name: 'ok',
                        trigger: () => {
                            $modal.modal('hide');
                        }
                    }
                ]
            );

            const hotpAuthUrl = document.createElement('pre');
            hotpAuthUrl.innerText = (new SecurityUtility()).encodeHtml(button.dataset.url)
            $modal.find(Hotp.selectors.modalBody).append(hotpAuthUrl);

        }).delegateTo(document, Hotp.selectors.authUrlButton);
    });
});
