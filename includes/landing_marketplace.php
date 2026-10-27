<?php if (!isset($landingProducts)) { http_response_code(404); exit; } ?>
<section class="landing-marketplace" id="soko" aria-labelledby="landing-soko-title">
    <div class="market-heading"><div><h2 id="landing-soko-title">Soko / Buy Goods</h2><p>Tafuta bidhaa, angalia bei na ongea na muuzaji kupitia WhatsApp.</p></div><a class="btn btn-outline-primary" href="marketplace">View all goods <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
    <form method="GET" action="marketplace" class="landing-goods-search"><label for="landing-goods-search">Search goods</label><div><input id="landing-goods-search" type="search" name="q" maxlength="100" placeholder="Simu, viatu, furniture..." autocomplete="off"><button class="btn btn-primary" type="submit"><i class="fas fa-search" aria-hidden="true"></i> Search Goods</button></div></form>
    <div class="market-grid">
    <?php foreach ($landingProducts as $item): $images=Marketplace::images($item['images']); ?>
        <article class="market-product"><a href="product?id=<?= (int)$item['id'] ?>" class="market-photo-link"><?php if ($images): ?><img src="<?= sanitize(SITE_URL.'/'.$images[0]) ?>" alt="<?= sanitize($item['title']) ?>" loading="lazy" width="480" height="360"><?php else: ?><div class="market-photo-empty"><i class="fas fa-store" aria-hidden="true"></i></div><?php endif; ?></a><div class="market-product-info"><small><?= sanitize(Marketplace::CATEGORIES[$item['category']]??'Goods') ?></small><h3><a href="product?id=<?= (int)$item['id'] ?>"><?= sanitize($item['title']) ?></a></h3><strong class="market-price"><?= formatCurrency($item['product_price']) ?></strong><p><?= sanitize($item['location']) ?></p><a class="btn btn-outline-primary" href="product?id=<?= (int)$item['id'] ?>">View product</a></div></article>
    <?php endforeach; ?>
    </div>
    <?php if (!$landingProducts): ?><p class="service-empty">Tembelea Soko kutafuta bidhaa zinazopatikana.</p><?php endif; ?>
    <p class="landing-market-note">Unaweza kuangalia bidhaa na kuwasiliana na muuzaji bila account.</p>
</section>
