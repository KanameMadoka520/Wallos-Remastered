<?php
// In-memory RFC fixtures only; never reads a Wallos account's actual 2FA secret.
spl_autoload_register(static function ($class) {
    foreach (['OTPHP\\'=>'OTPHP/', 'Psr\\Clock\\'=>'Psr/Clock/', 'ParagonIE\\ConstantTime\\'=>'constant_time_encoding/'] as $prefix=>$directory) {
        if (str_starts_with($class,$prefix)) require_once __DIR__.'/../libs/'.$directory.substr($class,strlen($prefix)).'.php';
    }
});
$checks=0;
function otp_check($ok,$message) {global $checks;if(!$ok) throw new RuntimeException($message);$checks++;}
function otp_rejects(callable $callback) {
    try {$callback();} catch (InvalidArgumentException $exception) {return true;}
    return false;
}
$secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // RFC 4226 / RFC 6238 public test vector.
$clock=new OTPHP\InternalClock();
try {
    $hotp=OTPHP\HOTP::createFromSecret($secret);
    foreach (['755224','287082','359152','969429','338314','254676','287922','162583','399871','520489'] as $counter=>$code) {
        otp_check($hotp->at($counter)===$code,'HOTP RFC vector '.$counter);
    }
    $totp=OTPHP\TOTP::createFromSecret($secret,$clock);$totp->setDigits(8);
    foreach ([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130'] as $time=>$code) {
        otp_check($totp->at($time)===$code,'TOTP RFC vector '.$time);
        otp_check($totp->verify($code,$time),'TOTP RFC verification '.$time);
    }
    foreach ([0,-1,11,50,PHP_INT_MAX,'50','bad',null,[],true,6.5,INF,NAN] as $digits) {
        otp_check(otp_rejects(fn()=>$totp->setParameter('digits',$digits)),'Reject unsafe digits without an Error');
    }
    foreach ([1,6,8,10] as $digits) {
        $totp->setDigits($digits);otp_check(strlen($totp->at(59))===$digits,'Safe digit bounds remain supported');
    }
    $base='otpauth://totp/Wallos:Fixture?secret='.$secret.'&issuer=Wallos';
    foreach (['digits=50','digits[foo]=6','parameters[foo]=bar','issuer_included_as_parameter=notabool','clock=bad','label=hijacked','issuer[foo]=bar','algorithm[foo]=sha1','secret[foo]=bad'] as $query) {
        otp_check(otp_rejects(fn()=>OTPHP\Factory::loadFromProvisioningUri($base.'&'.$query,$clock)),'Hostile URI yields the documented exception');
    }
    $imported=OTPHP\Factory::loadFromProvisioningUri($base.'&digits=8&algorithm=SHA1&period=30',$clock);
    otp_check($imported->at(59)==='94287082','Normal public URI still generates RFC value');
    otp_check($imported->getIssuer()==='Wallos' && $imported->getLabel()==='Fixture','URI label/issuer preserved');
    $roundTrip=OTPHP\Factory::loadFromProvisioningUri($imported->getProvisioningUri(),$clock);
    otp_check($roundTrip->at(59)===$imported->at(59),'Generated provisioning URI round trips');
    $imported->setParameter('parameters',['bad'=>'value']);
    otp_check($imported->at(59)==='94287082','Direct custom parameter cannot replace internal parameter map');
    $imported->setParameter('clock','bad');
    otp_check($imported->at(59)==='94287082','Direct custom parameter cannot replace readonly clock');
    echo "PASS: $checks OTPHP security/RFC compatibility checks.\n";
} catch(Throwable $error) {fwrite(STDERR,'FAIL: '.get_class($error).': '.$error->getMessage()."\n");exit(1);}
