# TYPO3 Extension ``mfa_hotp``

This extension adds the HOTP (hmac-based one-time password) MFA provider to
TYPO3, using the new MFA API, available since TYPO3 v11.1. It can furthermore
be used as an example extension on how to integrate a custom provider into TYPO3.

**Important**: For better understanding, especially for editors, the provider
is referred to as **Counter-based one-time password** in the TYPO3 backend.

**Note**: Since the TYPO3 MFA API is still experimental, changes in upcoming releases
are to be expected.

## Installation

You can install the extension via composer ``composer req o-ba/mfa-hotp``,
download the release packages ([zip](https://github.com/o-ba/hotp/archive/0.1.4.zip),
[tar.gz](https://github.com/o-ba/hotp/archive/0.1.4.tar.gz)) or via the
[TYPO3 extension repository](https://extensions.typo3.org/extension/mfa_hotp/).

## About HOTP

The HOTP MFA Provider is based on a shared secret, which will be exchanged
between an OTP application (or device) and TYPO3. Each code takes the initially
defined shared secret and an increasing counter value into account. Each code
is only valid once, since the counter value will be updated on both sides after
every authentication attempt. Therefore, this provider is also called
**Counter-based one-time password**.

To use this provider:

1. Navigate to the MFA module in the TYPO3 backend and click on "Setup"
2. Scan the QR-code or directly enter the shared secret in an OTP application or device
3. Enter the generated six-digit code in the corresponding field
4. Submit the form to activate the MFA provider
5. Alternatively also activate the built-in ``Recovery codes`` provider

## TYPO3 and multi-factor authentication

You can read more about the implementation in the official
[changelog](https://docs.typo3.org/c/typo3/cms-core/master/en-us/Changelog/11.1/Feature-93526-MultiFactorAuthentication.html).

## Further TYPO3 extensions adding MFA providers

* [mfa_yubikey](https://github.com/derhansen/mfa_yubikey)
* [mfa_webauthn](https://github.com/bnf/mfa_webauthn)

## Credits

Icons used in this repository are made by
[Freepik](https://www.flaticon.com/authors/freepik) from
[www.flaticon.com](https://www.flaticon.com/).
