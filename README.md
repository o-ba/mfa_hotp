# TYPO3 Extension ``mfa_hotp``

This extension adds the HOTP (hmac-based one-time password) MFA provider to
TYPO3, using the new MFA API, available since TYPO3 v11.1. It can furthermore
be used as an example extension on how to integrate a custom provider into TYPO3.

**Note**: Since the TYPO3 MFA API is still experimental, changes in upcoming releases
are to be expected.

## Installation

You can install the extension via composer ``composer req o-ba/mfa-hotp``,
download the release packages ([zip](https://github.com/o-ba/hotp/archive/0.1.0.zip),
[tar.gz](https://github.com/o-ba/hotp/archive/0.1.0.tar.gz)) or via the
[TYPO3 extension repository](https://extensions.typo3.org/extension/tailor_ext/).

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
