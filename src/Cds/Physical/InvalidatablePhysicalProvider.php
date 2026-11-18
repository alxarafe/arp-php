<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Cds\Physical;
interface InvalidatablePhysicalProvider { public function invalidate(): void; }