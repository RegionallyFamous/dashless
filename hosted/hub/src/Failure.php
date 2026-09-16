<?php
namespace Dashless\Hub;
final class Failure extends \RuntimeException {
    public function __construct(public readonly string $slug, string $message, public readonly int $status = 400) { parent::__construct($message); }
}
