<?php
function featureToken(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.sanitize(Auth::generateCSRF()).'">'; }
function featureStatus(string $status): string {
    $color='#854d0e';
    if(in_array($status,['approved','paid','published','completed','sold'],true))$color='#15803d';
    elseif(in_array($status,['rejected','refunded','cancelled','closed','failed','removed'],true))$color='#9f1239';
    elseif(in_array($status,['expired','draft'],true))$color='#475569';
    return '<span style="color:'.$color.';font-weight:700">'.sanitize(ucfirst(str_replace('_',' ',$status))).'</span>';
}

function featurePlatformBadge(string $platform): string {
    $brands = ['youtube'=>'YouTube','tiktok'=>'TikTok','facebook'=>'Facebook','instagram'=>'Instagram','x'=>'X','linkedin'=>'LinkedIn'];
    if (!isset($brands[$platform])) return '<span class="social-platform">'.sanitize($platform).'</span>';
    $source=sanitize(SITE_URL.'/assets/images/social-logos/'.$platform.'.svg');
    return '<span class="social-platform"><span class="social-platform-logo"><img src="'.$source.'" width="28" height="28" alt="" aria-hidden="true"></span><span>'.sanitize($brands[$platform]).'</span></span>';

}

function featureNavigation(string $active): void {
    $links = match ($active) {
        'marketplace', 'my-products', 'product' => ['marketplace'=>'Buy Goods', 'my-products'=>'Sell Products'],
        'social-tasks', 'promote' => ['social-tasks'=>'Social Media Tasks', 'promote'=>'Promote Account'],
        'quizzes' => ['quizzes'=>'Quiz'],
        default => [],
    };
    $links['dashboard'] = 'Dashboard';
?>
<nav class="feature-nav" aria-label="Section navigation">
<?php foreach($links as $url=>$label): ?>
<a class="btn <?= $active===$url?'btn-primary':'btn-outline-primary' ?>" href="<?= sanitize(SITE_URL.'/'.$url) ?>"><?= $label ?></a>
<?php endforeach; ?></nav>
<?php }

function featurePages(int $page,bool $more,string $param='page',array $extra=[]): void { ?>
<nav class="feature-nav" aria-label="Results pages">
<?php if($page>1): ?><a class="btn btn-outline-primary" href="?<?= sanitize(http_build_query($extra+[$param=>$page-1])) ?>">Previous</a><?php endif; ?>
<?php if($more): ?><a class="btn btn-outline-primary" href="?<?= sanitize(http_build_query($extra+[$param=>$page+1])) ?>">Next</a><?php endif; ?></nav>
<?php } ?>
