<?php
function featureToken(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.sanitize(Auth::generateCSRF()).'">'; }
function featureStatus(string $status): string { $color=in_array($status,['approved','paid','published','completed'],true)?'#15803d':(in_array($status,['rejected','refunded','cancelled','closed'],true)?'#9f1239':'#854d0e');return '<span style="color:'.$color.';font-weight:700">'.sanitize(ucfirst($status)).'</span>'; }
function featureNavigation(string $active): void { ?>
<nav class="feature-nav" aria-label="Account services">
<?php foreach(['social-tasks'=>'Social tasks','promote'=>'Promote account','loans'=>'Loan applications','dashboard'=>'Dashboard'] as $url=>$label): ?>
<a class="btn <?= $active===$url?'btn-primary':'btn-outline-primary' ?>" href="<?= sanitize(SITE_URL.'/'.$url) ?>"><?= $label ?></a>
<?php endforeach; ?></nav>
<?php }

function featurePages(int $page,bool $more,string $param='page',array $extra=[]): void { ?>
<nav class="feature-nav" aria-label="Results pages">
<?php if($page>1): ?><a class="btn btn-outline-primary" href="?<?= sanitize(http_build_query($extra+[$param=>$page-1])) ?>">Previous</a><?php endif; ?>
<?php if($more): ?><a class="btn btn-outline-primary" href="?<?= sanitize(http_build_query($extra+[$param=>$page+1])) ?>">Next</a><?php endif; ?></nav>
<?php } ?>
