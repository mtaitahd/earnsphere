<?php
/**
 * EarnSphere - Public Head Include
 * Mobile-first responsive header for public pages
 */
$pageTitle = $pageTitle ?? 'EarnSphere';
$pageDesc = $pageDesc ?? 'Earn money online through referrals. Build your network and earn commissions.';
$pageKeywords = $pageKeywords ?? 'EarnSphere, earn money online, referral, commission, passive income, Tanzania, TZS, MLM, network marketing, side hustle';
$pageUrl = $pageUrl ?? (SITE_URL . $_SERVER['REQUEST_URI']);

// Load security headers
require_once __DIR__ . '/security_headers.php';

// Generate CSRF token for AJAX meta tag
require_once __DIR__ . '/../classes/Auth.php';
Auth::initSession();
$csrfTokenForMeta = Auth::generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="description" content="<?= sanitize($pageDesc) ?>">
    <meta name="keywords" content="<?= sanitize($pageKeywords) ?>">
    <!-- SEO Keywords - Swahili -->
    <meta name="keywords" content="EarnSphere, earn money online Tanzania, referral program Tanzania, commission based income, passive income Tanzania, mtandao wa kipato, fursa za kipato Tanzania, pesa kwa rufaa, program ya rufaa Tanzania, make money online TZS, MLM Tanzania, network marketing Tanzania, side hustle Tanzania, biashara ya mtandao, kipato cha ziada Tanzania, pesa kwa kushare link, fursa za kazi za mtandaoni Tanzania, how to make money in Tanzania 2026, earning app Tanzania, pesa kwa kualika watu">    
    <meta name="twitter:keywords" content="earn money online Tanzania, referral program, passive income, kipato cha ziada, fursa Tanzania, pesa kwa rufaa, mtandao wa kipato, TZS earning, Tanzania online business">
    <meta name="geo.region" content="TZ">
    <meta name="geo.placename" content="Tanzania">
    <meta name="robots" content="index, follow">
    <meta name="author" content="EarnSphere">
    <link rel="canonical" href="<?= sanitize($pageUrl) ?>">
    
    <!-- Open Graph -->
    <meta property="og:title" content="<?= sanitize($pageTitle) ?> | EarnSphere">
    <meta property="og:description" content="<?= sanitize($pageDesc) ?>">
    <meta property="og:url" content="<?= sanitize($pageUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="EarnSphere">
    <meta property="og:locale" content="sw_TZ">
    <meta property="og:locale:alternate" content="en_TZ">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= sanitize($pageTitle) ?> | EarnSphere">
    <meta name="twitter:description" content="<?= sanitize($pageDesc) ?>">
    <meta name="theme-color" content="#72578B">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    
    <title><?= sanitize($pageTitle) ?> | EarnSphere</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/img/logoo.png">
    
    <!-- CSRF Token for AJAX -->
    <meta name="csrf-token" content="<?= $csrfTokenForMeta ?>">
    
    <!-- Preconnect -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/app.css">
</head>
<body>
