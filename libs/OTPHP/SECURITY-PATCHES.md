# Local security backports (2026-11-03)

This is the existing vendored OTPHP API, not a complete upgrade to 11.4.3.
Original upstream authorship and license remain unchanged.

Security semantics backported from upstream 11.4.3:

- GHSA-g7m4-839x-ch6v: `OTPInterface::MAX_DIGITS=10`, bounded integer validation in the parameter setter and getter.
- GHSA-2jx3-65f3-xr8r: only validated `label`/`issuer` parameters can address object properties. A provisioning URI accepts only scalar public OTP query parameters; unexpected parameters and failures during construction/population are rejected through the existing `InvalidArgumentException` interface.

Sources:
https://github.com/Spomky-Labs/otphp/security/advisories/GHSA-g7m4-839x-ch6v
https://github.com/Spomky-Labs/otphp/security/advisories/GHSA-2jx3-65f3-xr8r
https://github.com/Spomky-Labs/otphp/blob/11.4.3/src/OTP.php
https://github.com/Spomky-Labs/otphp/blob/11.4.3/src/Factory.php

Regression: `php tests/otphp_security_test.php`, and disposable-account HTTP enrollment/login/recovery/replay coverage in `tests/otphp_http_test.py`.
