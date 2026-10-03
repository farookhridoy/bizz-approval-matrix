<?php

namespace Bizzsol\ApprovalMatrix\Exceptions;

/** A mandatory step resolved to nobody (e.g. no reporting head configured). Never silently skipped. */
class NoApproverException extends ApprovalException
{
}
