<?php

declare(strict_types=1);

namespace Bo\Hotp\Mfa;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderInterface;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderPropertyManager;
use TYPO3\CMS\Core\Authentication\Mfa\MfaViewType;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * MFA provider for hmac-based one-time password authentication
 *
 * @author Oliver Bartsch <bo@cedev.de>
 */
class HotpProvider implements MfaProviderInterface
{
    private const MAX_ATTEMPTS = 3;

    protected Context $context;
    protected ResponseFactory $responseFactory;

    public function __construct(Context $context, ResponseFactory $responseFactory)
    {
        $this->context = $context;
        $this->responseFactory = $responseFactory;
    }

    /**
     * Check if a HOTP is given in the current request
     *
     * @param ServerRequestInterface $request
     * @return bool
     */
    public function canProcess(ServerRequestInterface $request): bool
    {
        return $this->getHotp($request) !== '';
    }

    /**
     * Evaluate if the provider is activated by checking the
     * active state and the secret from the provider properties.
     *
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function isActive(MfaProviderPropertyManager $propertyManager): bool
    {
        return $propertyManager->getProperty('active')
            && $propertyManager->getProperty('secret', '') !== '';
    }

    /**
     * Evaluate if the provider is temporarily locked by checking
     * the current attempts state from the provider properties.
     *
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function isLocked(MfaProviderPropertyManager $propertyManager): bool
    {
        $attempts = (int)$propertyManager->getProperty('attempts', 0);

        // Assume the provider is locked in case the maximum attempts are exceeded.
        // A provider however can only be locked if set up - an entry exists in database.
        return $propertyManager->hasProviderEntry() && $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Initialize view and forward to the appropriate implementation
     * based on the view type to be returned.
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @param string $type
     * @return ResponseInterface
     */
    public function handleRequest(
        ServerRequestInterface $request,
        MfaProviderPropertyManager $propertyManager,
        string $type
    ): ResponseInterface {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplateRootPaths(['EXT:mfa_hotp/Resources/Private/Templates/Mfa']);
        switch ($type) {
            case MfaViewType::SETUP:
                $this->prepareSetupView($view, $propertyManager);
                break;
            case MfaViewType::EDIT:
                $this->prepareEditView($view, $propertyManager);
                break;
            case MfaViewType::AUTH:
                $this->prepareAuthView($view, $propertyManager);
                break;
        }
        $response = $this->responseFactory->createResponse();
        $response->getBody()->write($view->assign('providerIdentifier', $propertyManager->getIdentifier())->render());
        return $response;
    }

    /**
     * Verify the given HOTP and update the provider properties in case the HOTP is valid.
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function verify(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager) || $this->isLocked($propertyManager)) {
            // Can not verify an inactive or locked provider
            return false;
        }

        $hotp = $this->getHotp($request);
        $secret = $propertyManager->getProperty('secret', '');
        $counter = $propertyManager->getProperty('counter');
        $hotpInstance = GeneralUtility::makeInstance(Hotp::class, $secret, $counter);
        if (!$hotpInstance->verifyHotp($hotp)) {
            // Allow resynchronization, since if this is the only provider and the counter
            // is out of sync, there would be no possibility to access the account again.
            // window=2 means, one upcoming counter will also be accepted.
            $newCounter = $hotpInstance->resyncCounter($hotp, 2);
            if ($newCounter === null) {
                $attempts = $propertyManager->getProperty('attempts', 0);
                $propertyManager->updateProperties(['attempts' => ++$attempts]);
                return false;
            }
            $counter = $newCounter;
        }
        // If the update fails, we must return FALSE even if HOTP authentication was successful
        // since the next attempt would then fail because the counter would be out of sync.
        return $propertyManager->updateProperties([
            'counter' => ++$counter,
            'attempts' => 0,
            'lastUsed' => $this->context->getPropertyFromAspect('date', 'timestamp'),
        ]);
    }

    /**
     * Activate the provider by checking the necessary parameters,
     * verifying the HOTP and storing the provider properties.
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function activate(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if ($this->isActive($propertyManager)) {
            // Can not activate an active provider
            return false;
        }

        if (!$this->canProcess($request)) {
            // Return since the request can not be processed by this provider
            return false;
        }

        $secret = (string)($request->getParsedBody()['secret'] ?? '');
        $checksum = (string)($request->getParsedBody()['checksum'] ?? '');
        if ($secret === '' || !hash_equals(GeneralUtility::hmac($secret, 'hotp-setup'), $checksum)) {
            // Return since the request does not contain the initially created secret
            return false;
        }

        $counter = 0;
        $hotp = $this->getHotp($request);
        $hotpInstance = GeneralUtility::makeInstance(Hotp::class, $secret, $counter);
        if (!$hotpInstance->verifyHotp($hotp)) {
            // Since some OTP applications start with 1 and don't evaluate parameters, we allow resync here
            $counter = $hotpInstance->resyncCounter($hotp, 2);
            if ($counter === null) {
                return false;
            }
        }

        // If valid, prepare the provider properties to be stored
        $properties = ['secret' => $secret, 'counter' => ++$counter, 'active' => true];
        if (($name = (string)($request->getParsedBody()['name'] ?? '')) !== '') {
            $properties['name'] = $name;
        }

        // Usually there should be no entry if the provider is not activated, but to prevent the
        // provider from being unable to activate again, we update the existing entry in such case.
        return $propertyManager->hasProviderEntry()
            ? $propertyManager->updateProperties($properties)
            : $propertyManager->createProviderEntry($properties);
    }

    /**
     * Handle the unlock action by resetting the attempts provider property
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function unlock(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager) || !$this->isLocked($propertyManager)) {
            // Can not unlock an inactive or not locked provider
            return false;
        }

        // Reset the attempts
        return $propertyManager->updateProperties(['attempts' => 0]);
    }

    /**
     * Handle the deactivate action. For security reasons, the provider entry
     * is completely deleted and setting up this provider again, will therefore
     * create a brand new entry.
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function deactivate(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager)) {
            // Can not deactivate an inactive provider
            return false;
        }

        // Delete the provider entry
        return $propertyManager->deleteProviderEntry();
    }

    /**
     * Update the provider data and also perform a resync if requested
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function update(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager) || $this->isLocked($propertyManager)) {
            // Can not update an inactive or locked provider
            return false;
        }

        // Update the user specified name for this provider
        $name = (string)($request->getParsedBody()['name'] ?? '');
        if ($name !== '') {
            $updated = $propertyManager->updateProperties(['name' => $name]);
            if (!$updated) {
                return false;
            }
        }

        // Resync the counter if requested
        $hotp = $this->getHotp($request);
        $secret = $propertyManager->getProperty('secret', '');
        $currentCounter = $propertyManager->getProperty('counter');
        if ($hotp !== '' && $secret !== '' && $currentCounter !== null) {
            $hotpInstance = GeneralUtility::makeInstance(Hotp::class, $secret, $currentCounter);
            $newCounter = $hotpInstance->resyncCounter($hotp);
            if ($newCounter === null) {
                // Return since the resync was not successful - maybe the client differs to much
                return false;
            }
            // If resync was successful save the new counter (increased by one for the next verification)
            $updated = $propertyManager->updateProperties(['counter' => ++$newCounter]);
            if (!$updated) {
                // Return since the properties could not be stored
                return false;
            }
        }

        // Provider successfully updated
        return true;
    }

    /**
     * Generate a new shared secret and create a qr-code for improved usability.
     * Set template and assign necessary variables for the setup view.
     *
     * @param StandaloneView $view
     * @param MfaProviderPropertyManager $propertyManager
     */
    protected function prepareSetupView(StandaloneView $view, MfaProviderPropertyManager $propertyManager): void
    {
        $userData = $propertyManager->getUser()->user ?? [];
        $secret = Hotp::generateEncodedSecret([(string)($userData['uid'] ?? ''), (string)($userData['username'] ?? '')]);
        $hotpInstance = GeneralUtility::makeInstance(Hotp::class, $secret, 0);
        $hotpAuthUrl = $hotpInstance->getHotpAuthUrl(
            (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'TYPO3'),
            (string)($userData['email'] ?? '') ?: (string)($userData['username'] ?? '')
        );
        $view->setTemplate('Setup');
        $view->assignMultiple([
            'secret' => $secret,
            'hotpAuthUrl' => $hotpAuthUrl,
            'qrCode' => $this->getSvgQrCode($hotpAuthUrl),
            // Generate hmac of the secret to prevent it from being changed in the setup from
            'checksum' => GeneralUtility::hmac($secret, 'hotp-setup'),
        ]);
    }

    /**
     * Set the template and assign necessary variables for the edit view
     *
     * @param StandaloneView $view
     * @param MfaProviderPropertyManager $propertyManager
     */
    protected function prepareEditView(StandaloneView $view, MfaProviderPropertyManager $propertyManager): void
    {
        $view->setTemplate('Edit');
        $view->assignMultiple([
            'name' => $propertyManager->getProperty('name'),
            'lastUsed' => $this->getDateTime($propertyManager->getProperty('lastUsed', 0)),
            'updated' => $this->getDateTime($propertyManager->getProperty('updated', 0)),
        ]);
    }

    /**
     * Set the template for the auth view where the user has to provide the HOTP
     *
     * @param StandaloneView $view
     * @param MfaProviderPropertyManager $propertyManager
     */
    protected function prepareAuthView(StandaloneView $view, MfaProviderPropertyManager $propertyManager): void
    {
        $view->setTemplate('Auth');
        $view->assign('isLocked', $this->isLocked($propertyManager));
    }

    /**
     * Internal helper method for fetching the HOTP from the request
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    protected function getHotp(ServerRequestInterface $request): string
    {
        return trim((string)($request->getQueryParams()['hotp'] ?? $request->getParsedBody()['hotp'] ?? ''));
    }

    /**
     * Internal helper method for generating a svg QR-code for OTP applications
     *
     * @param string content
     * @return string
     */
    protected function getSvgQrCode(string $content): string
    {
        $qrCodeRenderer = new ImageRenderer(
            new RendererStyle(225, 4),
            new SvgImageBackEnd()
        );

        return (new Writer($qrCodeRenderer))->writeString($content);
    }

    /**
     * Return the timestamp as local time (date string) by applying the globally configured format
     *
     * @param int $timestamp
     * @return string
     */
    protected function getDateTime(int $timestamp): string
    {
        if ($timestamp === 0) {
            return '';
        }

        return date(
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'],
            $timestamp
        ) ?: '';
    }
}
