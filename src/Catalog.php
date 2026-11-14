<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Dictionary;
use Alxarafe\Arp\Dictionary\Internal\{Scalar,Projection,Parser};
final readonly class Catalog
{
    private function __construct(private array $elements,private array $provenance) {}
    /** @internal Validated builder data only. */
    public static function fromValidated(array $elements,array $provenance): self
    {
        ksort($elements,SORT_STRING);
        // Freeze object graphs as JSON bytes. All accessors return detached projections.
        foreach($elements as &$e) $e=Projection::encode((object)$e); unset($e);
        return new self($elements,$provenance);
    }
    public function ids(): array {return array_keys($this->elements);}
    public function provenance(string $id): ?array {return $this->provenance[$id]??null;}
    public function resolve(string $id): array
    {
        if(!isset($this->elements[$id])) return self::missing($id);
        return ['valid'=>true,'issues'=>[],'descriptor'=>Parser::parse($this->elements[$id],'json')];
    }
    /** Portable descriptor JSON with exact numeric metadata, or null for an unknown ID. */
    public function descriptorJson(string $id): ?string {return $this->elements[$id]??null;}
    public function validate(string $id,mixed $portableValue): array
    {
        if(!isset($this->elements[$id])) return self::missing($id);
        $e=(array)Parser::parse($this->elements[$id],'json');
        return Scalar::validate($e,$portableValue);
    }
    private static function missing(string $id): array {return ['valid'=>false,'issues'=>[['code'=>'DICTIONARY_ELEMENT_NOT_FOUND','elementId'=>$id]]];}
}
