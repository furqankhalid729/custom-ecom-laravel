<?php

namespace Custom\GoogleCore\OAuth;

/**
 * The refresh token was revoked or expired: the user must connect again.
 */
class InvalidGrantException extends OAuthException {}
