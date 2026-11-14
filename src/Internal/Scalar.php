<?php
declare(strict_types=1);
namespace Alxarafe\Arp\Dictionary\Internal;
final class Scalar
{
    public const TYPES=['BOOLEAN','STRING','TEXT','INT8','UINT8','INT16','UINT16','INT32','UINT32','INT64','DECIMAL','UUID','DATE','TIME','DATETIME','ENUM','BINARY'];
    public static function validate(array $element,mixed $value): array
    {
        $id=$element['id']; $type=$element['type']; $c=(array)$element['constraints'];
        try {
            $normalized=self::normalize($value,$type);
            $failed=[];
            if(isset(Number::RANGES[$type]) || $type==='DECIMAL') {
                if(!Number::inRange($normalized,$type)) $failed[]='typeRange';
                if(isset($c['minimum']) && Number::compare($normalized,$c['minimum']) < (($c['exclusiveMinimum']??false)?1:0)) $failed[]='minimum';
                if(isset($c['maximum']) && Number::compare($normalized,$c['maximum']) > (($c['exclusiveMaximum']??false)?-1:0)) $failed[]='maximum';
                if(isset($c['multipleOf']) && !Number::multiple($normalized,$c['multipleOf'])) $failed[]='multipleOf';
                if(isset($c['precision'])) {
                    [, $integer,$fraction]=Number::parts($normalized);
                    if(Number::compare(ltrim($normalized,'-'),'1'.str_repeat('0',$c['precision']-$c['scale']))>=0) $failed[]='precision';
                    if(strlen($fraction)>$c['scale']) $failed[]='scale';
                }
            }
            if(in_array($type,['STRING','TEXT','ENUM','BINARY'],true)) {
                $length=$type==='BINARY' ? strlen(base64_decode($value,true)) : preg_match_all('/./us',$value);
                if(isset($c['minimumLength']) && Number::compare((string)$length,(string)$c['minimumLength'])<0) $failed[]='minimumLength';
                if(isset($c['maximumLength']) && Number::compare((string)$length,(string)$c['maximumLength'])>0) $failed[]='maximumLength';
                if(isset($c['pattern']) && !(new Pattern($c['pattern']))->matches($value)) $failed[]='pattern';
                if($type==='ENUM' && !in_array($value,$element['enumValues'],true)) $failed[]='enumValues';
            }
            if(!$failed) return ['valid'=>true,'normalizedValue'=>$normalized,'issues'=>[]];
            return ['valid'=>false,'issues'=>array_map(fn($key)=>['code'=>'DICTIONARY_VALUE_CONSTRAINT','elementId'=>$id,'constraint'=>$key],$failed)];
        } catch(Failure $e) { return ['valid'=>false,'issues'=>[['code'=>$e->errorCode,'elementId'=>$id]]]; }
    }
    private static function normalize(mixed $value,string $type): mixed
    {
        if($type==='BOOLEAN') {if(!is_bool($value)) self::invalid(); return $value;}
        if(!is_string($value) || preg_match('//u',$value)!==1) self::invalid();
        if(isset(Number::RANGES[$type]) || $type==='DECIMAL') return Number::parse($value,$type);
        if(in_array($type,['STRING','TEXT','ENUM'],true)) return $value;
        if($type==='UUID') {
            if(!preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/D',$value)) self::invalid();
            return strtolower($value);
        }
        if($type==='BINARY') {
            $decoded=base64_decode($value,true);
            if($decoded===false || base64_encode($decoded)!==$value) self::invalid(); return $value;
        }
        if($type==='DATE') {self::date($value); return $value;}
        if($type==='TIME') return self::time($value);
        if($type==='DATETIME') {
            if(!preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2})T([0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,7})?)(Z|[+-][0-9]{2}:[0-9]{2})\z/D',$value,$m)) self::invalid();
            self::date($m[1]); $time=self::time($m[2]); $offset=$m[3]; $seconds=0;
            if($offset!=='Z') {
                $h=(int)substr($offset,1,2); $min=(int)substr($offset,4,2);
                if($h>14 || $min>59 || ($h===14 && $min!==0)) self::invalid();
                $seconds=($h*3600+$min*60)*($offset[0]==='-'?-1:1);
            }
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$m[1].'T'.substr($time,0,8),new \DateTimeZone('UTC'));
            $utc=$date->modify(sprintf('%+d seconds',-$seconds));
            $year=(int)$utc->format('Y'); if($year<1 || $year>9999) self::invalid();
            return $utc->format('Y-m-d\TH:i:s').substr($time,8).'Z';
        }
        throw new \LogicException('Unsupported validated type');
    }
    private static function date(string $value): void
    {
        if(!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$value,$m) || (int)$m[1]<1 || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) self::invalid();
    }
    private static function time(string $value): string
    {
        if(!preg_match('/\A([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,7}))?\z/D',$value,$m) || (int)$m[1]>23 || (int)$m[2]>59 || (int)$m[3]>59) self::invalid();
        return substr($value,0,8).'.'.str_pad($m[4]??'',7,'0');
    }
    private static function invalid(): never { throw new Failure('DICTIONARY_VALUE_TYPE'); }
}
