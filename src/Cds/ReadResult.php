<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Dictionary\Cds;
use Alxarafe\Arp\Dictionary\Internal\{Parser,Projection};
/** Immutable exact bytes; every projection is a detached object graph. */
final readonly class ReadResult
{
    private string $json;
    public bool $valid;
    /** @internal Resolver/store output only. */
    public function __construct(array $projection)
    {
        $this->valid = $projection['valid'];
        $this->json = Projection::encode((object)$projection);
    }
    public function toJson(): string { return $this->json; }
    public function projection(): object { return Parser::parse($this->json, 'json'); }
    public static function failure(string $code, string $at, string $family = 'definition-invalid'): self
    {
        return new self(['valid'=>false,'issues'=>[['family'=>$family,'code'=>'CDS_READ_'.$code,'at'=>$at]]]);
    }
}