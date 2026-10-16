<?php

namespace App\Services\Mines\Exceptions;

/** 401 or 403: the connection's token is invalid, expired or lacks the ability. */
class CredentialRejected extends MinesException {}
