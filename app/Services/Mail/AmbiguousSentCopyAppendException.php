<?php

namespace App\Services\Mail;

/** APPEND may have succeeded although the server acknowledgement was lost. */
class AmbiguousSentCopyAppendException extends \RuntimeException {}
