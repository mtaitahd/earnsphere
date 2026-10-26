<?php
require_once __DIR__.'/classes/Auth.php';require_once __DIR__.'/classes/Marketplace.php';require_once __DIR__.'/includes/service_view.php';Auth::initSession();
$id=(int)($_GET['id']??0);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    Auth::requireAuth();
    if(!Auth::validateCSRF()){http_response_code(403);exit('Invalid security token.');}
    try{Marketplace::report((int)$_SESSION['user_id'],$id,$_POST['reason']??'');setFlash('success','Report received. A moderator will review this advertisement.');}
    catch(InvalidArgumentException $e){setFlash('error',$e->getMessage());}
    catch(Throwable $e){error_log('Product report: '.$e->getMessage());setFlash('error','Your report could not be submitted. Try again later.');}
    header('Location: '.SITE_URL.'/product?id='.$id);exit;
}
$product=null;try{$product=Marketplace::publicListing($id);}catch(Throwable $e){error_log('Product details: '.$e->getMessage());}
if(!$product)http_response_code(404);
$pageTitle=$product?$product['title'].' | Soko':'Product unavailable';$pageDesc=$product?mb_substr($product['description'],0,150):'This product is no longer available.';
$viewer=isset($_SESSION['user_id'])?Auth::getUser():null;include __DIR__.'/includes/public_head.php'; ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/member-services.css"><main class="services-page"><nav class="feature-nav"><a class="btn btn-outline-primary" href="marketplace">Back to Soko</a><a class="btn btn-outline-primary" href="my-products">Sell a product</a></nav><?php displayFlash(); ?>
<?php if(!$product): ?><div class="service-card"><h1>Product unavailable</h1><p>This advertisement is not public, has expired, or the product is no longer available.</p></div><?php else: ?>
<article class="service-card"><div class="market-gallery"><?php foreach(Marketplace::images($product['images']) as $index=>$image): ?><a href="<?= sanitize(SITE_URL.'/'.$image) ?>" target="_blank" rel="noopener noreferrer"><img src="<?= sanitize(SITE_URL.'/'.$image) ?>" alt="<?= sanitize($product['title']) ?> — photo <?= $index+1 ?>" width="720" height="540" loading="lazy"></a><?php endforeach; ?></div>
<p class="text-muted"><?= sanitize(Marketplace::CATEGORIES[$product['category']]??'Goods') ?> · <?= sanitize($product['location']) ?></p><h1><?= sanitize($product['title']) ?></h1><p class="market-price"><?= formatCurrency($product['product_price']) ?></p><p class="service-note"><?= sanitize($product['description']) ?></p><a class="btn btn-success" href="<?= sanitize(Marketplace::whatsappLink($product)) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-whatsapp me-1"></i> Chat on WhatsApp</a><p>Seller contact: <?= sanitize($product['whatsapp']) ?></p><p class="text-muted">Ask the seller about availability, product condition, delivery and how you will complete the purchase.</p></article>
<?php if($viewer && $viewer['role']==='user' && $viewer['status']==='active' && (int)$viewer['id']!==(int)$product['user_id']): ?>
<details class="service-card"><summary>Report this advertisement</summary><form method="POST"><?php featureToken(); ?><label for="report-reason">What is the problem?</label><textarea id="report-reason" name="reason" maxlength="1000" rows="3" required></textarea><button class="btn btn-outline-danger">Send report</button></form></details>
<?php elseif(!$viewer): ?><p><a href="login">Log in</a> to report an advertisement.</p><?php endif; ?>
<?php endif; ?></main><?php include __DIR__.'/includes/public_foot.php'; ?>
