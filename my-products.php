<?php
require_once __DIR__.'/includes/member_feature.php';require_once __DIR__.'/classes/Marketplace.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        $action=$_POST['action']??'';$id=(int)($_POST['id']??0);
        if($action==='create'){Marketplace::create((int)$user['id'],$_POST,is_array($_FILES['photos']??null)?$_FILES['photos']:[]);setFlash('success','Product saved as a private draft. Pay the advertising fee to submit it for review.');unset($_SESSION['product_draft']);}
        elseif($action==='pay'){
            if(($_POST['accepted']??'')!=='1')throw new InvalidArgumentException('Accept the advertising fee before paying.');
            Marketplace::pay((int)$user['id'],$id);setFlash('success','Advertising fee recorded. Approval is required before publication; check the current listing status below.');
        }elseif(in_array($action,['cancel','sold'],true)){Marketplace::sellerAction((int)$user['id'],$id,$action);setFlash('success',$action==='sold'?'Product marked sold and hidden from Soko.':'Unpublished advertisement cancelled. Any advertising fee was refunded once.');}
        else throw new InvalidArgumentException('Unknown listing action.');
    }catch(Throwable $e){
        if(($_POST['action']??'')==='create' && strlen(json_encode($_POST)?:'')<=20000)$_SESSION['product_draft']=$_POST;
        if($e instanceof InvalidArgumentException)setFlash('error',$e->getMessage());
        else{error_log('Seller listing action: '.$e->getMessage());setFlash('error','Your request could not be completed. Refresh and check status before trying again.');}
    }
    header('Location: '.SITE_URL.'/my-products');exit;
}
$draft=is_array($_SESSION['product_draft']??null)?$_SESSION['product_draft']:[];
function productField(array $draft,string $key,string $default=''):string{return sanitize(is_scalar($draft[$key]??null)?(string)$draft[$key]:$default);}
$policy=Marketplace::policy();$available=true;$listings=[];$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*20;
try{$listings=Database::fetchAll("SELECT * FROM marketplace_listings WHERE user_id=? ORDER BY id DESC LIMIT 21 OFFSET $offset",[$user['id']]);}catch(Throwable $e){$available=false;error_log('Seller listings: '.$e->getMessage());}
$more=count($listings)>20;$listings=array_slice($listings,0,20);$wallet=Wallet::getWallet((int)$user['id']);$pageTitle='Sell products';include __DIR__.'/includes/public_head.php'; ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/member-services.css"><main class="services-page"><h1>My products / Sell a product</h1><?php featureNavigation('my-products');displayFlash(); ?>
<p><a class="btn btn-outline-primary" href="marketplace">Browse Soko / Buy Goods</a></p><p>Available wallet earnings: <strong><?= formatCurrency($wallet['withdrawable_balance']) ?></strong></p>
<div class="service-notice">Tangazo linahitaji malipo na approval kabla ya kuonekana kwa umma. Ada ni <?= formatCurrency($policy['fee']) ?> kwa siku <?= (int)$policy['days'] ?> kuanzia approval. Malipo yanatumia available earnings za wallet. Tangazo likikataliwa kabla ya kuchapishwa, ada inarudishwa. Ununuzi wa bidhaa unakamilishwa kati yako na mnunuzi kupitia WhatsApp.</div>
<?php if(!$available): ?><p class="service-empty">Product advertising is temporarily unavailable.</p><?php elseif(!$policy['enabled']): ?><p class="service-empty">Admin has not enabled new advertising requests yet.</p><?php else: ?>
<details class="service-card" <?= $draft?'open':'' ?>><summary>Create a product advertisement</summary><p class="service-note"><?= sanitize($policy['rules']) ?></p>
<form method="POST" enctype="multipart/form-data"><?php featureToken(); ?><input type="hidden" name="action" value="create"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)) ?>"><input type="hidden" name="policy_hash" value="<?= Marketplace::policyHash($policy) ?>">
<label for="product-title">Product name</label><input id="product-title" name="title" maxlength="150" value="<?= productField($draft,'title') ?>" required>
<div class="service-grid"><div><label for="product-category">Category</label><select id="product-category" name="category"><?php foreach(Marketplace::CATEGORIES as $key=>$label): ?><option value="<?= $key ?>" <?= ($draft['category']??'')===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div><div><label for="product-price">Product price (TZS)</label><input id="product-price" name="product_price" type="number" min="1" max="1000000000" value="<?= productField($draft,'product_price') ?>" required></div></div>
<label for="product-description">Description, condition and delivery details</label><textarea id="product-description" name="description" maxlength="5000" rows="5" required><?= productField($draft,'description') ?></textarea>
<label for="product-location">Location / city</label><input id="product-location" name="location" maxlength="150" value="<?= productField($draft,'location') ?>" required>
<label for="product-whatsapp">WhatsApp number</label><input id="product-whatsapp" name="whatsapp" type="tel" maxlength="30" value="<?= productField($draft,'whatsapp',$user['phone']) ?>" required>
<label for="product-photos">Product photos (1–4)</label><input id="product-photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required><p class="text-muted">JPG, PNG or WebP; up to 5 MB each. Smaller files may be required by your hosting upload limit. Photos must be selected again after a failed submission.</p>
<label><input type="checkbox" name="accepted" value="1" required>I accept the advertising rules and the <?= formatCurrency($policy['fee']) ?> fee for <?= (int)$policy['days'] ?> days after approval. My product photos, description, location and WhatsApp number will be public if approved. No sales are guaranteed.</label><button class="btn btn-primary">Save product draft</button></form></details>
<?php endif; ?>
<h2>My advertisements</h2><?php if(!$listings && $available): ?><p>No products submitted yet.</p><?php endif; ?>
<?php foreach($listings as $listing): $images=Marketplace::images($listing['images']);$expired=$listing['status']==='approved' && $listing['expires_at']<=gmdate('Y-m-d H:i:s'); ?>
<article class="service-card"><h2><?= sanitize($listing['title']) ?></h2><p>#<?= (int)$listing['id'] ?> · <?= featureStatus($expired?'expired':$listing['status']) ?> · Fee: <?= featureStatus($listing['fee_status']) ?></p><div class="market-thumbnails"><?php foreach($images as $image): ?><img src="<?= sanitize(SITE_URL.'/'.$image) ?>" alt="<?= sanitize($listing['title']) ?>" width="120" height="90" loading="lazy"><?php endforeach; ?></div><p class="market-price"><?= formatCurrency($listing['product_price']) ?></p><p class="service-note"><?= sanitize($listing['description']) ?></p><p><?= sanitize($listing['location']) ?> · <?= sanitize($listing['whatsapp']) ?></p><p>Advertising fee <?= formatCurrency($listing['ad_fee']) ?> · <?= (int)$listing['duration_days'] ?> days</p>
<?php if($listing['expires_at']): ?><p>Expires: <?= formatLedgerTime($listing['expires_at']) ?> EAT</p><?php endif; ?>
<details><summary>Rules accepted for this advertisement</summary><?php $acceptedPolicy=json_decode($listing['policy_snapshot'],true)?:[]; ?><p class="service-note"><?= sanitize($acceptedPolicy['rules']??'') ?></p></details>
<?php if($listing['review_note']): ?><p>Moderator: <?= sanitize($listing['review_note']) ?></p><?php endif; ?>
<?php if($listing['status']==='draft'): ?><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= (int)$listing['id'] ?>"><label><input type="checkbox" name="accepted" value="1" required>Pay <?= formatCurrency($listing['ad_fee']) ?> from my available wallet earnings and submit for review.</label><button class="btn btn-primary">Pay <?= formatCurrency($listing['ad_fee']) ?> & submit</button></form><?php endif; ?>
<?php if(in_array($listing['status'],['draft','pending_review'],true)): ?><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$listing['id'] ?>"><button class="btn btn-outline-danger">Cancel<?= $listing['fee_status']==='paid'?' and refund advertising fee':'' ?></button></form><?php endif; ?>
<?php if($listing['status']==='approved' && !$expired): ?><a class="btn btn-outline-primary" href="product?id=<?= (int)$listing['id'] ?>">View public advertisement</a><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="sold"><input type="hidden" name="id" value="<?= (int)$listing['id'] ?>"><button class="btn btn-outline-success">Mark product sold</button></form><?php endif; ?>
</article><?php endforeach; ?><?php featurePages($page,$more); ?></main><?php include __DIR__.'/includes/public_foot.php'; ?>
