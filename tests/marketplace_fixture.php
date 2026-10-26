<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/social_loans_fixture.php';require __DIR__.'/../classes/Marketplace.php';
foreach(explode(';',file_get_contents(__DIR__.'/../database/marketplace.sql')) as $statement){
    if(!trim($statement))continue;
    $statement=str_replace('INT UNSIGNED AUTO_INCREMENT PRIMARY KEY','INTEGER PRIMARY KEY AUTOINCREMENT',$statement);
    $statement=preg_replace('/UNIQUE KEY [a-z_]+ \(/i','UNIQUE (',$statement);
    $statement=preg_replace('/, KEY [a-z_]+ \([^)]*\)/i','',$statement);
    $statement=preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/',' )',$statement);
    $db->exec($statement);
}
class FixtureProductImages extends MarketplaceImages {
    public int $calls=0;
    public function store(array $files):array{$this->calls++;return ['uploads/marketplace/'.str_repeat('a',32).'.png'];}
}
function sampleAd(string $key):array{return ['title'=>'Phone for sale','description'=>'Good condition, original box included.','category'=>'electronics','product_price'=>'50000','location'=>'Dar es Salaam','whatsapp'=>'+255 712 345 678','request_key'=>$key,'accepted'=>'1','policy_hash'=>Marketplace::policyHash(Marketplace::policy())];}
function enableMarketplace():void{Marketplace::savePolicy(3,['enabled'=>'1','fee'=>'1500','days'=>'7','rules'=>'Advertise real permitted products with accurate prices and original photos. Rejected unpublished ads receive a full fee refund.']);}
