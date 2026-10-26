<?php
require_once __DIR__.'/classes/Auth.php';require_once __DIR__.'/classes/Marketplace.php';require_once __DIR__.'/includes/service_view.php';
Auth::initSession();
$category=is_string($_GET['category']??null)&&isset(Marketplace::CATEGORIES[$_GET['category']])?$_GET['category']:'';
$search=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,100):'';
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*24;$products=[];$available=true;
try {
    $where="l.status='approved' AND l.fee_status='paid' AND l.expires_at>? AND u.status='active' AND u.role='user'";$params=[gmdate('Y-m-d H:i:s')];
    if($category!==''){$where.=' AND l.category=?';$params[]=$category;}
    if($search!==''){$where.=' AND (l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)';array_push($params,'%'.$search.'%','%'.$search.'%','%'.$search.'%');}
    $products=Database::fetchAll("SELECT l.id,l.title,l.product_price,l.location,l.category,l.images FROM marketplace_listings l JOIN users u ON u.id=l.user_id WHERE $where ORDER BY l.approved_at DESC,l.id DESC LIMIT 25 OFFSET $offset",$params);
}catch(Throwable $e){$available=false;error_log('Marketplace public list: '.$e->getMessage());}
$more=count($products)>24;$products=array_slice($products,0,24);$pageTitle='Soko / Buy Goods';$pageDesc='Browse products advertised by EarnSphere sellers and contact them on WhatsApp.';include __DIR__.'/includes/public_head.php'; ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/member-services.css">
<main class="services-page market-page"><div class="market-heading"><div><h1>Soko / Buy Goods</h1><p>Gundua bidhaa, angalia bei na ongea na muuzaji kupitia WhatsApp.</p></div><a class="btn btn-primary" href="my-products">Sell a product</a></div>
<nav class="feature-nav"><a class="btn btn-outline-primary" href="dashboard">My account</a><a class="btn btn-outline-primary" href="my-products">My products</a></nav>
<form method="GET" class="service-card market-filter"><div><label for="market-search">Search products or location</label><input id="market-search" name="q" maxlength="100" value="<?= sanitize($search) ?>" placeholder="Phone, shoes, Dar es Salaam..."></div><div><label for="market-category">Category</label><select id="market-category" name="category"><option value="">All categories</option><?php foreach(Marketplace::CATEGORIES as $key=>$label): ?><option value="<?= $key ?>" <?= $category===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div><button class="btn btn-primary">Search</button></form>
<?php if(!$available): ?><p class="service-empty">Soko is temporarily unavailable. Please try again later.</p><?php elseif(!$products): ?><p class="service-empty">Hakuna bidhaa zilizopatikana. Jaribu search au category nyingine.</p><?php endif; ?>
<div class="market-grid"><?php foreach($products as $product): $images=Marketplace::images($product['images']); ?><article class="market-product"><a href="product?id=<?= (int)$product['id'] ?>" class="market-photo-link"><?php if($images): ?><img src="<?= sanitize(SITE_URL.'/'.$images[0]) ?>" alt="<?= sanitize($product['title']) ?>" loading="lazy" width="480" height="360"><?php else: ?><div class="market-photo-empty">Product photo</div><?php endif; ?></a><div class="market-product-info"><small><?= sanitize(Marketplace::CATEGORIES[$product['category']]??'Goods') ?></small><h2><a href="product?id=<?= (int)$product['id'] ?>"><?= sanitize($product['title']) ?></a></h2><strong class="market-price"><?= formatCurrency($product['product_price']) ?></strong><p><?= sanitize($product['location']) ?></p><a class="btn btn-outline-primary" href="product?id=<?= (int)$product['id'] ?>">View product</a></div></article><?php endforeach; ?></div>
<?php featurePages($page,$more,'page',['q'=>$search,'category'=>$category]); ?>
<p class="text-muted">Wasiliana na muuzaji kukubaliana kuhusu malipo ya bidhaa na delivery. EarnSphere hukusanya ada ya tangazo; bei ya bidhaa hulipwa kwa muuzaji kwa makubaliano yenu.</p></main><?php include __DIR__.'/includes/public_foot.php'; ?>
