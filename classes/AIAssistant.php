<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

class AIAssistant {

    const CONTENT_TYPES = [
        'whatsapp_message',
        'facebook_post',
        'instagram_caption',
        'tiktok_caption',
        'telegram_message',
        'twitter_post',
        'blog_article',
        'referral_sms',
        'referral_email',
        'marketing_slogan',
        'catchy_headline',
        'video_script',
        'voiceover_script',
        'cta',
        'referral_story',
        'carousel_text',
        'poster_content',
        'short_ad',
        'long_ad',
        'hook',
        'follow_up_message',
        'customer_reply',
        'objection_handling',
    ];

    const TONES = [
        'professional',
        'funny',
        'luxury',
        'business',
        'youth',
        'corporate',
        'motivational',
        'simple_swahili',
        'english',
        'mixed_swahili_english',
    ];

    const PLATFORMS = [
        'whatsapp',
        'facebook',
        'instagram',
        'telegram',
        'tiktok',
        'linkedin',
        'sms',
    ];

    public static function getContentTypeLabel(string $type): string {
        $labels = [
            'whatsapp_message'   => 'WhatsApp Message',
            'facebook_post'      => 'Facebook Post',
            'instagram_caption'  => 'Instagram Caption',
            'tiktok_caption'     => 'TikTok Caption',
            'telegram_message'   => 'Telegram Message',
            'twitter_post'       => 'Twitter Post',
            'blog_article'       => 'Blog Article',
            'referral_sms'       => 'Referral SMS',
            'referral_email'     => 'Referral Email',
            'marketing_slogan'   => 'Marketing Slogan',
            'catchy_headline'    => 'Catchy Headline',
            'video_script'       => 'Video Script',
            'voiceover_script'   => 'Voice-over Script',
            'cta'                => 'Call To Action',
            'referral_story'     => 'Referral Story',
            'carousel_text'      => 'Carousel Text',
            'poster_content'     => 'Poster Content',
            'short_ad'           => 'Short Ad',
            'long_ad'            => 'Long Ad',
            'hook'               => 'Hook',
            'follow_up_message'  => 'Follow-up Message',
            'customer_reply'     => 'Customer Reply',
            'objection_handling' => 'Objection Handling',
        ];
        return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    public static function getToneLabel(string $tone): string {
        $labels = [
            'professional'         => 'Professional',
            'funny'                => 'Funny',
            'luxury'               => 'Luxury',
            'business'             => 'Business',
            'youth'                => 'Youth',
            'corporate'            => 'Corporate',
            'motivational'         => 'Motivational',
            'simple_swahili'       => 'Simple Swahili',
            'english'              => 'English',
            'mixed_swahili_english' => 'Mixed Swahili + English',
        ];
        return $labels[$tone] ?? ucfirst($tone);
    }

    public static function getPlatformShareUrl(string $platform, string $text, ?string $refCode = null): string {
        $siteUrl = SITE_URL;
        $refLink = $refCode ? "$siteUrl/register?ref=" . urlencode($refCode) : $siteUrl;
        $encoded = urlencode($text);

        return match ($platform) {
            'whatsapp'  => "https://wa.me/?text=$encoded",
            'facebook'  => "https://www.facebook.com/sharer/sharer.php?quote=$encoded&u=" . urlencode($refLink),
            'instagram' => '', // Instagram doesn't support direct URL sharing - return empty
            'telegram'  => "https://t.me/share/url?url=" . urlencode($refLink) . "&text=$encoded",
            'tiktok'    => '', // TikTok doesn't support direct URL sharing
            'linkedin'  => "https://www.linkedin.com/sharing/share-offsite/?url=" . urlencode($refLink),
            'sms'       => "sms:?&body=$encoded",
            default     => '',
        };
    }

    public static function checkRateLimit(int $userId): array {
        $maxPerHour = (int) app_setting('ai_max_generations_per_hour', 10);
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $count = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM ai_content_history WHERE user_id = ? AND created_at >= ?",
            [$userId, $oneHourAgo]
        );

        $used = (int) ($count['cnt'] ?? 0);
        $remaining = max(0, $maxPerHour - $used);

        return [
            'used'      => $used,
            'remaining' => $remaining,
            'limit'     => $maxPerHour,
            'can_generate' => $remaining > 0,
        ];
    }

    public static function getSystemPromptForTone(string $tone): string {
        $prompts = [
            'professional' => 'Use a professional, polished tone. Write in clear, formal language. Be trustworthy and authoritative.',
            'funny' => 'Use a funny, entertaining tone. Include humor, jokes, and playful language. Make people laugh while still conveying the message.',
            'luxury' => 'Use a luxury, premium tone. Sound exclusive and high-end. Emphasize quality, class, and sophistication.',
            'business' => 'Use a business-focused tone. Be direct, results-oriented, and persuasive. Focus on ROI and opportunity.',
            'youth' => 'Use a young, energetic tone. Be relatable, casual, and trendy. Use modern slang appropriately.',
            'corporate' => 'Use a corporate, institutional tone. Be formal, structured, and trustworthy. Sound like an established company.',
            'motivational' => 'Use a motivational, inspiring tone. Be uplifting and encouraging. Use powerful, emotional language.',
            'simple_swahili' => 'Write in simple, clear Swahili. Use basic vocabulary that everyone can understand. Be warm and friendly.',
            'english' => 'Write in clear, natural English. Use standard grammar. Be accessible and easy to understand.',
            'mixed_swahili_english' => 'Mix Swahili and English naturally (Sheng). Use conversational East African language that feels authentic and relatable.',
        ];
        return $prompts[$tone] ?? $prompts['professional'];
    }

    public static function getPlatformGuidelines(string $contentType): string {
        $guidelines = [
            'whatsapp_message' => 'Keep it short and personal. Max 300 characters. Use emojis naturally. Include a clear call to action.',
            'facebook_post' => 'Longer format (100-300 words). Use storytelling. Add emojis. End with a question or CTA. Include hashtags.',
            'instagram_caption' => 'Engaging first line. 50-150 words. Use relevant hashtags (5-10). Add emojis. Include CTA.',
            'tiktok_caption' => 'Super short and punchy. Hook in first 2 words. Use trending phrases. Max 100 characters.',
            'telegram_message' => 'Medium length. Clear structure. Use bullet points for benefits. Include link. Add emojis.',
            'twitter_post' => 'Very concise. Max 280 characters. Use hashtags (1-3). Make every word count.',
            'blog_article' => 'Complete article 300-500 words. Title, introduction, body with 3-5 key points, conclusion, CTA.',
            'referral_sms' => 'Very short. Max 160 characters. Clear offer. Name and link. No fluff.',
            'referral_email' => 'Full email format: subject line, greeting, body, benefits, CTA, signature. 200-400 words.',
            'marketing_slogan' => 'One short memorable phrase. 5-10 words. Easy to remember. Brand-focused.',
            'catchy_headline' => 'Attention-grabbing headline. 8-15 words. Use power words. Create curiosity.',
            'video_script' => 'Script format with scene directions, dialogue, and timing for a 30-60 second video.',
            'voiceover_script' => 'Voice-over script for a 30-60 second video. Conversational tone. Clear pacing cues.',
            'cta' => 'Short persuasive call to action. 5-15 words. Create urgency. Tell exactly what to do.',
            'referral_story' => 'Brief success story format. 100-200 words. Problem → Solution → Result structure.',
            'carousel_text' => 'Multi-slide format. 5-7 slides. Each slide: headline + 1-2 sentences. Sequential flow.',
            'poster_content' => 'Poster layout: headline, subheadline, 3-5 benefits, CTA, hashtags. Concise and scannable.',
            'short_ad' => 'Short ad copy. 30-50 words. Hook, problem, solution, CTA. Persuasive and urgent.',
            'long_ad' => 'Long-form ad. 200-400 words. Story-driven. Features, benefits, social proof, CTA.',
            'hook' => 'Super strong opening. 1-3 sentences. Must stop scroll. Create curiosity or emotion.',
            'follow_up_message' => 'Friendly follow-up. 50-100 words. Reference previous interaction. Add value. Gentle CTA.',
            'customer_reply' => 'Helpful customer service response. 30-80 words. Address concern. Provide solution. Be empathetic.',
            'objection_handling' => 'Address a specific objection. 50-100 words. Validate concern. Reframe. Provide evidence. Close.',
        ];
        return $guidelines[$contentType] ?? 'Write clear, engaging content appropriate for the platform.';
    }

    public static function getImagePrompt(array $context): string {
        $contentType = $context['content_type'] ?? 'poster_content';
        $tone = $context['tone'] ?? 'professional';

        $styleMap = [
            'professional' => 'clean, minimalist, corporate style',
            'funny' => 'colorful, playful, cartoon-style',
            'luxury' => 'elegant, gold accents, premium dark background',
            'business' => 'modern, sleek, data-driven visuals',
            'youth' => 'vibrant, energetic, social media style',
            'corporate' => 'formal, structured, brand-focused',
            'motivational' => 'dramatic lighting, inspiring, cinematic',
            'simple_swahili' => 'warm, community-focused, friendly',
            'english' => 'clean, universal, modern',
            'mixed_swahili_english' => 'colorful East African vibe, modern urban',
        ];

        $style = $styleMap[$tone] ?? 'modern, professional';

        $prompt = "Create a professional marketing image for a referral/commission platform called EarnSphere. ";
        $prompt .= "Style: $style. ";
        $prompt .= "Layout: Clean composition with ample white space, modern typography, ";
        $prompt .= "professional photo or illustration style. ";
        $prompt .= "Colors: Deep green (#0A3622) as primary, gold (#D4A843) as accent, with white text. ";
        $prompt .= "Include: Modern icons or graphics representing referrals, growth, earnings, network. ";
        $prompt .= "Lighting: Soft, professional, well-lit. ";
        $prompt .= "Typography: Bold, modern sans-serif headlines. ";
        $prompt .= "Overall: Looks like a premium fintech marketing material from a leading African company. ";
        $prompt .= "The image should be suitable for $contentType content type.";

        return $prompt;
    }

    public static function generateContent(int $userId, string $contentType, string $tone, string $language, string $customPrompt = ''): array {
        $rateLimit = self::checkRateLimit($userId);
        if (!$rateLimit['can_generate']) {
            return [
                'success' => false,
                'error' => 'Umeshindwa kufikia kikomo cha AI generation kwa saa hii. Tafadhali jaribu tena baadaye. (Max: ' . $rateLimit['limit'] . '/saa)',
            ];
        }

        if (!in_array($contentType, self::CONTENT_TYPES)) {
            return ['success' => false, 'error' => 'Invalid content type'];
        }

        if (!in_array($tone, self::TONES)) {
            $tone = 'professional';
        }

        $user = Database::fetchOne("SELECT full_name, referral_code FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $siteName = app_setting('site_name', 'EarnSphere');
        $regFee = (int) app_setting('registration_fee', 11500);
        $commL1 = (int) app_setting('commission_l1', 2000);
        $commL2 = (int) app_setting('commission_l2', 1200);
        $commL3 = (int) app_setting('commission_l3', 800);
        $refLink = getReferralLink($user['referral_code']);
        $userName = $user['full_name'];
        $refCode = $user['referral_code'];

        $generated = self::generateLocalFallback($contentType, $tone, $language, $userName, $refCode, $regFee, $commL1, $siteName, $refLink, $customPrompt);

        if (!$generated['success']) {
            return $generated;
        }

        $content = $generated['content'];

        $contentId = Database::insert('ai_content_history', [
            'user_id'          => $userId,
            'content_type'     => $contentType,
            'tone'             => $tone,
            'language'         => $language,
            'prompt_input'     => $customPrompt ?: null,
            'generated_content' => $content,
            'word_count'       => str_word_count($content),
            'character_count'  => strlen($content),
            'ip_address'       => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return [
            'success'  => true,
            'content'  => $content,
            'id'       => $contentId,
            'type'     => $contentType,
            'tone'     => $tone,
            'rate_limit' => [
                'remaining' => $rateLimit['remaining'] - 1,
                'limit'     => $rateLimit['limit'],
            ],
        ];
    }

    private static function pick(array $arr) {
        return $arr[array_rand($arr)];
    }

    private static function number(int $n): string {
        return number_format($n);
    }

    private static function sw(array $en, array $sw, bool $useSw): string {
        $arr = $useSw ? $sw : $en;
        return $arr[array_rand($arr)];
    }

    private static function buildContext(int $regFee, int $commL1, int $commL2, int $commL3, string $siteName, string $link): array {
        $combo = rand(100, 300);
        $weekly = $commL1 * 5;
        $monthly = $commL1 * 20;
        return compact('regFee', 'commL1', 'commL2', 'commL3', 'siteName', 'link', 'combo', 'weekly', 'monthly');
    }

    private static function toneWords(string $tone): array {
        $all = [
            'professional' => [
                'en' => ['opportunity', 'earn', 'income', 'join', 'start', 'grow', 'build', 'achieve', 'success', 'financial', 'future', 'proven'],
                'adj' => ['reliable', 'trusted', 'professional', 'legitimate', 'established', 'secure', 'efficient'],
                'emoji' => ['✅', '📊', '💼', '📈', '🔹', '⭐'],
            ],
            'funny' => [
                'en' => ['cha-ching', 'ka-ching', 'booya', 'easy', 'cake', 'party', 'dance', 'laugh', 'fun', 'awesome'],
                'adj' => ['easy-peasy', 'super simple', 'ridiculously easy', 'insanely good', 'mind-blowing'],
                'emoji' => ['😂', '🎉', '🤑', '💃', '🔥', '🚀', '😎'],
            ],
            'luxury' => [
                'en' => ['premium', 'exclusive', 'elite', 'luxury', 'sophisticated', 'refined', 'exceptional', 'boutique'],
                'adj' => ['prestigious', 'high-end', 'world-class', 'superior', 'remarkable', 'distinguished'],
                'emoji' => ['💎', '✨', '👑', '🌟', '💫', '🔱'],
            ],
            'business' => [
                'en' => ['ROI', 'revenue', 'profit', 'scale', 'grow', 'invest', 'portfolio', 'asset', 'venture'],
                'adj' => ['strategic', 'scalable', 'profitable', 'efficient', 'measurable', 'sustainable'],
                'emoji' => ['📈', '💼', '📊', '🎯', '⚡', '💹'],
            ],
            'youth' => [
                'en' => ['vibe', 'grind', 'hustle', 'flex', 'boss', 'mood', 'lit', 'fire', 'slay', 'bestie', 'squad'],
                'adj' => ['next-level', 'crazy good', 'fire', 'dope', 'epic', 'wavy'],
                'emoji' => ['🔥', '💯', '👊', '⚡', '🙌', '✨', '🕶️'],
            ],
            'corporate' => [
                'en' => ['organization', 'structure', 'system', 'platform', 'solution', 'enterprise', 'initiative'],
                'adj' => ['comprehensive', 'integrated', 'systematic', 'authorized', 'certified', 'institutional'],
                'emoji' => ['🏢', '📋', '✅', '📌', '🔒'],
            ],
            'motivational' => [
                'en' => ['dream', 'believe', 'achieve', 'inspire', 'transform', 'empower', 'breakthrough', 'limitless'],
                'adj' => ['unlimited', 'extraordinary', 'incredible', 'unstoppable', 'phenomenal', 'life-changing'],
                'emoji' => ['💪', '🔥', '🌟', '🚀', '✨', '🎯', '👑'],
            ],
        ];

        $t = $all[$tone] ?? $all['professional'];
        $sw = [
            'en' => ['zipata', 'pata', 'jipatie', 'anzisha', 'jiunge', 'kua', 'jenga', 'fanikiwa', 'mafanikio'],
            'adj' => ['rahisi', 'halali', 'haraka', 'salama', 'bora', 'nzuri'],
            'emoji' => ['✅', '💰', '💵', '📲', '🔥', '⭐', '👌'],
        ];

        if (in_array($tone, ['simple_swahili', 'mixed_swahili_english'])) {
            $t['en'] = $sw['en'];
            $t['adj'] = $sw['adj'];
            $t['emoji'] = $sw['emoji'];
        }

        return $t;
    }

    private static function enSentences(array $ctx): array {
        $f = self::number($ctx['regFee']);
        $c1 = self::number($ctx['commL1']);
        $c2 = self::number($ctx['commL2']);
        $co = self::number($ctx['combo']);
        $w = self::number($ctx['weekly']);
        $m = self::number($ctx['monthly']);
        $site = $ctx['siteName'];
        $link = $ctx['link'];

        return [
            "Join $site today for just TZS $f and start earning TZS $c1 per referral!",
            "Only TZS $f to start. Earn TZS $c1 for every friend who joins via your link.",
            "Turn TZS $f into TZS $m+ monthly. Refer friends, earn commissions, withdraw anytime.",
            "Make TZS $c1 per referral with $site. One-time fee of just TZS $f.",
            "Your earning potential: TZS $c1 per person × your network = unlimited income.",
            "Stop scrolling. Start earning. $site gives you TZS $c1 per referral.",
            "TZS $f is all it takes to unlock a TZS $c1-per-referral income stream.",
            "With $site, every friend you invite earns you TZS $c1. Level 2 brings TZS $c2 more.",
            "Imagine earning TZS $w this week just by sharing your link. That's $site.",
            "Why work extra hours when TZS $f can start you on a TZS $co+ income journey?",
        ];
    }

    private static function swSentences(array $ctx): array {
        $f = self::number($ctx['regFee']);
        $c1 = self::number($ctx['commL1']);
        $c2 = self::number($ctx['commL2']);
        $co = self::number($ctx['combo']);
        $w = self::number($ctx['weekly']);
        $m = self::number($ctx['monthly']);
        $site = $ctx['siteName'];
        $link = $ctx['link'];

        return [
            "Jiunge na $site kwa TZS $f tu na anza kupata TZS $c1 kwa kila mwaliko!",
            "TZS $f pekee kuanza. Pata TZS $c1 kwa kila rafiki anayejiunga kupitia link yako.",
            "Badilisha TZS $f hadi TZS $m+ kwa mwezi. Alika marafiki na withdraw wakati wowote.",
            "Pata TZS $c1 kwa kila mtu unayemwalika $site. Malipo ya mara moja TZS $f.",
            "Uwezo wako wa mapato: TZS $c1 kwa kila mtu × mtandao wako = mapato yasiyo na kikomo.",
            "Acha kuteleza kwenye simu. Anza kupata. $site inakupa TZS $c1 kwa kila mwaliko.",
            "TZS $f tu kufungua mlango wa mapato ya TZS $c1 kwa kila mwaliko.",
            "Kila rafiki unayemwalika $site anakuletea TZS $c1. Ngazi ya 2 ni TZS $c2 zaidi.",
            "Fikiria kupata TZS $w wiki hii kwa kushare link yako. Hiyo ndiyo $site.",
            "Kwa nini ufanye kazi ya ziada wakati TZS $f inaweza kukuanzisha safari ya mapato?",
        ];
    }

    private static function mixedSentences(array $ctx): array {
        $f = self::number($ctx['regFee']);
        $c1 = self::number($ctx['commL1']);
        $c2 = self::number($ctx['commL2']);
        $co = self::number($ctx['combo']);
        $w = self::number($ctx['weekly']);
        $site = $ctx['siteName'];
        $link = $ctx['link'];

        return [
            "Jiunge na $site kwa TZS $f tu. Start earning TZS $c1 per referral!",
            "TZS $f one-time fee. After that, unapata TZS $c1 kwa kila mtu.",
            "Anza na TZS $f, earn TZS $c1 per referral. Simple right?",
            "Una withdraw M-Pesa instantly. That's the beauty of $site.",
            "Pata TZS $c1 per referral, level 2 inakupa TZS $c2 extra. Hii ni fursa!",
        ];
    }

    private static function pickSentence(bool $useSw, bool $mixed, array $ctx): string {
        if ($mixed) return self::pick(self::mixedSentences($ctx));
        return self::pick($useSw ? self::swSentences($ctx) : self::enSentences($ctx));
    }

    private static function generateWhatsApp(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s = self::pickSentence($sw, $mixed, $ctx);
        $e = self::pick($tw['emoji']);
        $a = self::pick($tw['adj']);
        $greet = $sw ? self::pick(['Habari! 👋', 'Mambo! 😊', 'Niaje! ✌️', 'Sema! 👋']) : self::pick(["Hey! 👋", "Hello! 👋", "Hi there! 😊", "What's up! ✌️"]);
        $close = $sw ? self::pick(["📲 $link", "👇 $link", "👉 $link", "🔗 $link"]) : self::pick(["📲 $link", "👇 $link", "👉 $link", "🔗 $link"]);
        return "$greet\n$s\n\n$e $a opportunity! Withdraw to M-Pesa/Airtel instantly.\n\n$close";
    }

    private static function generateFacebook(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s1 = self::pickSentence($sw, $mixed, $ctx);
        $s2 = self::pickSentence($sw, $mixed, $ctx);
        $e = self::pick($tw['emoji']);
        $tag1 = $sw ? '#EarnSphere' : '#EarnSphere';
        $tags = $sw
            ? self::pick([['#Kipato', '#Mapato', '#Tanzania', '#Fursa'], ['#Biashara', '#Mtandao', '#KipatoChaZiada'], ['#Pesa', '#FursaTanzania', '#MapatoYaZiada']])
            : self::pick([['#ExtraIncome', '#WorkFromHome', '#Tanzania', '#PassiveIncome'], ['#SideHustle', '#ReferralProgram', '#MakeMoneyTZ'], ['#FinancialFreedom', '#PassiveIncome', '#TanzaniaBusiness']]);
        $head = $sw ? self::pick(["🚀 **MAPATO YA ZIADA YANAWEZEKANA!**", "💰 **FURSA YAKO YA KIPATO IKO HAPA!**", "🔥 **ANZA KUPATA MAPATO LEO!**"]) : self::pick(["🚀 **EARN EXTRA INCOME FROM HOME!**", "💰 **YOUR INCOME OPPORTUNITY IS HERE!**", "🔥 **START EARNING TODAY!**"]);
        $q = $sw ? "Je, uko tayari kubadilisha maisha yako?" : "Are you ready to change your life?";
        return "$head\n\n$q\n\n$s1\n\n$s2\n\n$e **Withdraw M-Pesa / Airtel Money**\n\n📲 $link\n\n" . implode(' ', array_map(fn($t) => "#$t", $tags));
    }

    private static function generateInstagram(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s = self::pickSentence($sw, $mixed, $ctx);
        $e = self::pick($tw['emoji']);
        $hook = $sw
            ? self::pick(["Unataka mapato ya ziada?", "Kipato cha ziada kiko hapa!", "Njia rahisi ya kupata pesa!"])
            : self::pick(["Want extra income?", "Your side hustle is here!", "The easiest way to earn!"]);
        $tags = $sw ? '#EarnSphere #Kipato #Tanzania #Mapato #Fursa #Mpesa #Biashara' : '#EarnSphere #ExtraIncome #Tanzania #SideHustle #PassiveIncome #Mpesa #MakeMoney';
        return "$hook\n\n$s\n\n$e Withdraw to M-Pesa/Airtel\n\n📲 Tap link in bio\n\n$tags";
    }

    private static function generateTikTok(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s = self::pickSentence($sw, $mixed, $ctx);
        if (strlen($s) > 100) $s = substr($s, 0, 97) . '...';
        $hook = $sw
            ? self::pick(["Hii ni fursa yako! 🚀", "Usikose hii! 🔥", "Njoo tuoneshwe! 💰"])
            : self::pick(["Don't scroll past this! 🚀", "This is your sign! 🔥", "Watch this! 💰"]);
        return "$hook\n$s";
    }

    private static function generateTelegram(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s1 = self::pickSentence($sw, $mixed, $ctx);
        $s2 = self::pickSentence($sw, $mixed, $ctx);
        $e = self::pick($tw['emoji']);
        $head = $sw ? "🚀 **Fursa ya Kipato cha Ziada!**" : "🚀 **Extra Income Opportunity!**";
        $close = $sw ? "@EarnSphere - Fursa Yako ya Mapato!" : "@EarnSphere - Your Income Opportunity!";
        return "$head\n\n$s1\n\n$s2\n\n$e Withdraw via M-Pesa / Airtel Money\n\n🔗 $link\n\n$close";
    }

    private static function generateTwitter(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s = self::pickSentence($sw, $mixed, $ctx);
        $e = self::pick($tw['emoji']);
        $content = $sw
            ? "$e $s $e\n📲 $link\n#EarnSphere #Tanzania"
            : "$e $s $e\n📲 $link\n#EarnSphere #Tanzania";
        if (strlen($content) > 280) $content = substr($content, 0, 277) . '...';
        return $content;
    }

    private static function generateSMS(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s = self::pickSentence($sw, $mixed, $ctx);
        $content = $sw
            ? "$s 📲 $link"
            : "$s 📲 $link";
        if (strlen($content) > 160) $content = substr($content, 0, 157) . '...';
        return $content;
    }

    private static function generateEmail(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s1 = self::pickSentence($sw, $mixed, $ctx);
        $s2 = self::pickSentence($sw, $mixed, $ctx);
        $s3 = self::pickSentence($sw, $mixed, $ctx);
        $greet = $sw ? "Habari rafiki!" : "Hello friend!";
        $body = $sw
            ? "Nina fursa nzuri ya kukutambulisha. $siteName inalipa wanachama wake kwa kualika marafiki."
            : "I have an amazing opportunity to share with you. $siteName pays its members for inviting friends.";
        $close = $sw ? "Karibu sana!" : "Welcome aboard!";
        return "$greet\n\n$body\n\n$s1\n\n$s2\n\n$s3\n\n$close\n$link";
    }

    private static function generateBlog(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s = self::pickSentence($sw, $mixed, $ctx);
        $f = self::number($ctx['regFee']);
        $c1 = self::number($ctx['commL1']);
        $site = $ctx['siteName'];
        $t = $sw ? "Jinsi Ya Kupata Mapato Ya Ziada Na $site" : "How To Earn Extra Income With $site";
        $p1 = $sw
            ? "Katika makala hii, utajifunza jinsi ya kuanza kupata mapato ya ziada kwa kutumia $site. Ni rahisi na unaweza kufanya kutoka nyumbani."
            : "In this article, you'll learn how to start earning extra income using $site. It's easy and you can do it from home.";
        $p2 = $sw
            ? "Kwa malipo ya mara moja ya TZS $f tu, unapata nafasi ya kupata TZS $c1 kwa kila mtu unayemwalika. Hakuna malipo ya kila mwezi."
            : "For a one-time payment of only TZS $f, you get the chance to earn TZS $c1 for every person you invite. No monthly fees.";
        $p3 = $sw
            ? "Pesa zako unaweza kuzitoa wakati wowote kupitia M-Pesa au Airtel Money. Hii ndiyo fursa yako ya kubadilisha maisha yako."
            : "You can withdraw your money anytime via M-Pesa or Airtel Money. This is your opportunity to change your life.";
        return "**$t**\n\n$p1\n\n$s\n\n$p2\n\n$p3\n\n📲 $link";
    }

    private static function generateSlogan(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $p1 = $sw
            ? self::pick(['Pata Kila Siku', 'Mapato Ya Kweli', 'Fursa Yako', 'Badilisha Maisha Yako', 'Kipato Chako'])
            : self::pick(['Earn Every Day', 'Real Income', 'Your Opportunity', 'Change Your Life', 'Your Income']);
        $p2 = $sw
            ? self::pick(['Jiunge, Alika, Pata!', 'Anza Leo, Pata Leo!', 'Weka, Shiriki, Pata!'])
            : self::pick(['Join, Invite, Earn!', 'Start Today, Earn Today!', 'Share, Refer, Earn!']);
        return "$p1. $p2";
    }

    private static function generateHeadline(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $c1 = self::number($ctx['commL1']);
        if ($sw) {
            return self::pick([
                "Unataka Kupata TZS $c1 Kwa Kila Mtu Unayemwalika? Hii Ndiyo Fursa Yako!",
                "Pata TZS $c1 Kwa Kila Rafiki! Anza Leo Bila Kuchelewa!",
                "TZS $c1 Kwa Mwaliko? Ndiyo, Inawezekana! Jiunge Sasa!",
                "Njia Rahisi Ya Kupata TZS $c1 Kwa Kila Mtu Unayemwalika!",
            ]);
        }
        return self::pick([
            "Want to Earn TZS $c1 for Every Person You Invite? This Is Your Opportunity!",
            "Earn TZS $c1 Per Referral! Start Today Without Delay!",
            "TZS $c1 Per Referral? Yes, It's Possible! Join Now!",
            "The Easiest Way to Earn TZS $c1 for Every Friend You Invite!",
        ]);
    }

    private static function generateVideoScript(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $f = self::number($ctx['regFee']);
        $hook = $sw ? "Unataka kupata TZS $c1 kwa kushare link tu?" : "Want to earn TZS $c1 just by sharing a link?";
        $body = $sw
            ? "Hii ndiyo $site. Jiunge kwa TZS $f, alika marafiki, na kila mtu anayekubali anakuletea TZS $c1 moja kwa moja."
            : "This is $site. Join for TZS $f, invite friends, and every person who accepts brings you TZS $c1 directly.";
        $cta = $sw ? "Bonyeza link kwenye description, jiunge, na anza leo!" : "Click the link in the description, join, and start today!";
        $outro = $sw ? "Kumbuka: Pesa unazotoa M-Pesa. Rahisi!" : "Remember: Withdraw straight to M-Pesa. Easy!";
        return "[HOOK]\n$hook\n\n[BODY]\n$body\n\n[CTA]\n$cta\n\n[OUTRO]\n$outro\n\n📲 $link";
    }

    private static function generateVoiceover(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $f = self::number($ctx['regFee']);
        $lines = [];
        if ($sw) {
            $lines[] = self::pick(["Hujambo, nina fursa ya kipekee kwako.", "Habari, nina kitu cha kushiriana nawe."]);
            $lines[] = "$site inakupa nafasi ya kupata kipato cha ziada.";
            $lines[] = "Kwa TZS $f tu, unaweza kuanza kupata TZS $c1 kwa kila mtu unayemwalika.";
            $lines[] = self::pick(["Pesa unazotoa M-Pesa au Airtel wakati wowote.", "Withdraw moja kwa moja kwenye simu yako."]);
            $lines[] = self::pick(["Anza leo na ubadili maisha yako!", "Usikose fursa hii muhimu!"]);
        } else {
            $lines[] = self::pick(["Hey, I've got a unique opportunity for you.", "Hello, I have something to share with you."]);
            $lines[] = "$site gives you the chance to earn extra income.";
            $lines[] = "For just TZS $f, you can start earning TZS $c1 for every person you invite.";
            $lines[] = self::pick(["Withdraw to M-Pesa or Airtel anytime.", "Money goes straight to your phone."]);
            $lines[] = self::pick(["Start today and change your life!", "Don't miss this important opportunity!"]);
        }
        return implode("\n\n[PAUSE]\n\n", $lines) . "\n\n📲 $link";
    }

    private static function generateCTA(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $f = self::number($ctx['regFee']);
        $e = self::pick($tw['emoji']);
        if ($sw) {
            return self::pick([
                "ANZA SASA! Bonyeza link, jiunge kwa TZS $f, na anza kupata mapato leo! $e",
                "BONYEZA HAPA! Jiunge kwa TZS $f, alika marafiki, pata TZS " . self::number($ctx['commL1']) . " kwa kila mtu! $e",
                "USIKOSEE! TZS $f tu kuanza. Anza mapato yako leo! $e $link",
            ]);
        }
        return self::pick([
            "START NOW! Click the link, join for TZS $f, and start earning today! $e",
            "CLICK HERE! Join for TZS $f, invite friends, earn TZS " . self::number($ctx['commL1']) . " per person! $e",
            "DON'T MISS OUT! Only TZS $f to start. Begin your earnings today! $e $link",
        ]);
    }

    private static function generateReferralStory(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $w = self::number($ctx['weekly']);
        $m = self::number($ctx['monthly']);
        $names = $sw ? ['Juma', 'Asha', 'Baraka', 'Neema', 'Salum', 'Mwajuma'] : ['John', 'Sarah', 'Peter', 'Grace', 'David', 'Mary'];
        $name = self::pick($names);
        if ($sw) {
            return "\"Sikujua nitaweza kupata kipato cha ziada kwa urahisi kiasi hiki!\"\n\n$name alijiunga $site mwezi uliopita. Alianza kwa kuwaeleza marafiki zake kuhusu fursa hii.\n\nMatokeo? Leo anapata TZS $w kwa wiki! Anatumia muda wake wa ziada tu, na pesa inaingia moja kwa moja.\n\n\"Kila mtu ninayemwalika ananiletea TZS $c1. Nimewahi kupata TZS $m kwa mwezi!\"\n\n📲 $link\n#EarnSphere";
        }
        return "\"I never knew earning extra income could be this easy!\"\n\n$name joined $site last month. They started by telling friends about this opportunity.\n\nThe result? They now earn TZS $w per week! Using only spare time, money goes directly to M-Pesa.\n\n\"Everyone I invite earns me TZS $c1. I once made TZS $m in a month!\"\n\n📲 $link\n#EarnSphere";
    }

    private static function generateCarousel(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $f = self::number($ctx['regFee']);
        $c1 = self::number($ctx['commL1']);
        $site = $ctx['siteName'];
        if ($sw) {
            $slides = [
                "SLIDE 1: Unataka Mapato Ya Ziada?",
                "SLIDE 2: $site Inakupa Fursa!",
                "SLIDE 3: Jiunge Kwa TZS $f Tu",
                "SLIDE 4: Pata TZS $c1 Kwa Kila Mwaliko",
                "SLIDE 5: Withdraw M-Pesa / Airtel",
                "SLIDE 6: Anza Leo! $link",
            ];
        } else {
            $slides = [
                "SLIDE 1: Want Extra Income?",
                "SLIDE 2: $site Has The Solution!",
                "SLIDE 3: Join For Only TZS $f",
                "SLIDE 4: Earn TZS $c1 Per Referral",
                "SLIDE 5: Withdraw to M-Pesa / Airtel",
                "SLIDE 6: Start Today! $link",
            ];
        }
        if (rand(0, 1)) shuffle($slides);
        return implode("\n\n---\n\n", $slides);
    }

    private static function generatePosterContent(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $f = self::number($ctx['regFee']);
        $c1 = self::number($ctx['commL1']);
        $site = $ctx['siteName'];
        $h = $sw
            ? self::pick(['Pata Kila Siku!', 'Mapato Yako Yanaanza!', 'Fursa Yako Ya Kipato!'])
            : self::pick(['Earn Every Day!', 'Your Income Starts Now!', 'Your Income Opportunity!']);
        $sh = $sw
            ? self::pick(["Jiunge na $site leo na anza kupata mapato.", "Njia rahisi ya kupata kipato cha ziada."])
            : self::pick(["Join $site today and start earning.", "The easy way to earn extra income."]);
        $b = $sw
            ? "• Malipo ya mara moja TZS $f\n• Pata TZS $c1 kwa kila mwaliko\n• Withdraw papo hapo M-Pesa/Airtel"
            : "• One-time payment TZS $f\n• Earn TZS $c1 per referral\n• Instant withdrawal M-Pesa/Airtel";
        $cta = $sw ? "ANZA SASA!" : "START NOW!";
        $tags = $sw ? '#EarnSphere #Kipato #Mapato #Tanzania #Fursa' : '#EarnSphere #ExtraIncome #Referral #Tanzania';
        return "HEADLINE: $h\nSUBHEADLINE: $sh\n\nBENEFITS:\n$b\n\nCTA: $cta\n\nHASHTAGS: $tags\n\nCOLORS: Dark Green (#0A3622), Gold (#D4A843)\nLAYOUT: Mobile-friendly vertical poster";
    }

    private static function generateShortAd(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $f = self::number($ctx['regFee']);
        $c1 = self::number($ctx['commL1']);
        $site = $ctx['siteName'];
        $e = self::pick($tw['emoji']);
        $s = self::pickSentence($sw, $mixed, $ctx);
        if ($sw) {
            return "$e **MAPATO YAKO HAPA!**\n\n$site inakupa TZS $c1 kwa kila mtu unayemwalika. Malipo ya mara moja TZS $f.\n\n✅ Halali\n✅ Haraka\n✅ Hakuna vikwazo\n\n👉 $link";
        }
        return "$e **EXTRA INCOME IS HERE!**\n\n$site gives you TZS $c1 for every person you invite. One-time payment TZS $f.\n\n✅ Legitimate\n✅ Fast\n✅ No hidden fees\n\n👉 $link";
    }

    private static function generateLongAd(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s1 = self::pickSentence($sw, $mixed, $ctx);
        $s2 = self::pickSentence($sw, $mixed, $ctx);
        $s3 = self::pickSentence($sw, $mixed, $ctx);
        $site = $ctx['siteName'];
        $e = self::pick($tw['emoji']);
        if ($sw) {
            $h = "FURSA YA MAPATO YA ZIADA";
            $close = "Usikose nafasi hii. Tuma ujumbe au bonyeza link leo!";
        } else {
            $h = "EXTRA INCOME OPPORTUNITY";
            $close = "Don't miss this chance. Send a message or click the link today!";
        }
        return "**$h**\n\n$s1\n\n$s2\n\n$s3\n\n$e $close\n\n📲 $link";
    }

    private static function generateHook(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $c1 = self::number($ctx['commL1']);
        $m = self::number($ctx['monthly']);
        if ($sw) {
            return self::pick([
                "Ukiwaambia watu 5 leo, utaamka kesho na TZS " . self::number($ctx['weekly']) . " kwenye akaunti yako.",
                "Hii inaweza kuwa siku unayobadilisha maisha yako. TZS $c1 kwa kila mtu.",
                "Unajua unaweza kupata TZS $c1 kwa kutuma link tu? Hii ni kweli!",
                "Saa 24 tu zinakutosha kuanza kupata TZS $m kwa mwezi.",
            ]);
        }
        return self::pick([
            "Tell 5 people today, wake up tomorrow with TZS " . self::number($ctx['weekly']) . " in your account.",
            "This could be the day you change your life. TZS $c1 per person.",
            "You can earn TZS $c1 by just sending a link? It's true!",
            "Just 24 hours is all you need to start earning TZS $m per month.",
        ]);
    }

    private static function generateFollowUp(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $e = self::pick($tw['emoji']);
        if ($sw) {
            return self::pick([
                "Habari! Nilikutumia ujumbe kuhusu $site. Umeifikiria? Pata TZS $c1 kwa kila mwaliko $e",
                "Sijasikia kutoka kwako. Je, una maswali yoyote kuhusu $site? Niko tayari kukusaidia!",
                "Hii ni fursa nzuri sana usiipite. $site inakupa TZS $c1 kwa kila mtu $e",
                "Nikutumie maelezo zaidi kuhusu $site? Unaweza kupata TZS $c1 leo tu!",
            ]);
        }
        return self::pick([
            "Hey! I sent you a message about $site. Have you thought about it? Earn TZS $c1 per referral $e",
            "Haven't heard from you. Any questions about $site? Happy to help!",
            "This is such a great opportunity. $site gives you TZS $c1 per person $e",
            "Should I send you more details about $site? You could earn TZS $c1 today!",
        ]);
    }

    private static function generateCustomerReply(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        if ($sw) {
            return self::pick([
                "Asante kwa kuwasiliana! Ndiyo, $site ni halali na tayari kuna wanachama wengi wanaopata mapato. Pata TZS $c1 kwa kila mwaliko.",
                "Karibu! Kujiunga ni rahisi. Malipo ya TZS " . self::number($ctx['regFee']) . " tu na unaanza kupata TZS $c1 kwa kila mtu unayemwalika.",
                "Ndiyo, pesa unazotoa M-Pesa au Airtel. Hakuna vikwazo. Unaweza kutoa wakati wowote baada ya kupata commission.",
            ]);
        }
        return self::pick([
            "Thanks for reaching out! Yes, $site is legitimate with many active members earning. Earn TZS $c1 per referral.",
            "Welcome! Joining is easy. Just TZS " . self::number($ctx['regFee']) . " and you start earning TZS $c1 for every person you invite.",
            "Yes, you withdraw to M-Pesa or Airtel. No restrictions. Withdraw anytime after earning commissions.",
        ]);
    }

    private static function generateObjectionHandling(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $f = self::number($ctx['regFee']);
        if ($sw) {
            return self::pick([
                "Naelewa wasiwasi wako. Lakini $site ni tofauti - malipo ya mara moja TZS $f tu, hakuna ada za kila mwezi. Na wanachama wanaondoa pesa zao kila siku.",
                "Watu wengi wana swali hilo. Tofauti na biashara nyingine, $site inalipa TZS $c1 moja kwa moja kwa kila mwaliko. Unaona matokeo papo hapo.",
                "Hakuna hatari. TZS $f ni malipo ya mara moja tu. Na unaweza kupata TZS $c1 kwa kila mtu - inamaanisha ukialika watu 2 tu, umepata faida yako.",
            ]);
        }
        return self::pick([
            "I understand your concern. But $site is different - one-time payment of TZS $f only, no monthly fees. Members withdraw their money daily.",
            "Many people ask that. Unlike other businesses, $site pays TZS $c1 directly per referral. You see results immediately.",
            "No risk. TZS $f is a one-time payment. And you earn TZS $c1 per person - invite just 2 people and you've made your money back.",
        ]);
    }

    private static function generateLocalFallback(string $contentType, string $tone, string $language, string $userName, string $refCode, int $regFee, int $commL1, string $siteName, string $refLink, string $customPrompt): array {
        $sw = in_array($tone, ['simple_swahili', 'mixed_swahili_english']);
        $mixed = $tone === 'mixed_swahili_english';
        $ctx = self::buildContext($regFee, $commL1, 1200, 800, $siteName, $refLink);
        $tw = self::toneWords($tone);

        $generators = [
            'whatsapp_message'   => fn() => self::generateWhatsApp($sw, $mixed, $ctx, $tw),
            'facebook_post'      => fn() => self::generateFacebook($sw, $mixed, $ctx, $tw),
            'instagram_caption'  => fn() => self::generateInstagram($sw, $mixed, $ctx, $tw),
            'tiktok_caption'     => fn() => self::generateTikTok($sw, $mixed, $ctx, $tw),
            'telegram_message'   => fn() => self::generateTelegram($sw, $mixed, $ctx, $tw),
            'twitter_post'       => fn() => self::generateTwitter($sw, $mixed, $ctx, $tw),
            'blog_article'       => fn() => self::generateBlog($sw, $mixed, $ctx, $tw),
            'referral_sms'       => fn() => self::generateSMS($sw, $mixed, $ctx, $tw),
            'referral_email'     => fn() => self::generateEmail($sw, $mixed, $ctx, $tw),
            'marketing_slogan'   => fn() => self::generateSlogan($sw, $mixed, $ctx, $tw),
            'catchy_headline'    => fn() => self::generateHeadline($sw, $mixed, $ctx, $tw),
            'video_script'       => fn() => self::generateVideoScript($sw, $mixed, $ctx, $tw),
            'voiceover_script'   => fn() => self::generateVoiceover($sw, $mixed, $ctx, $tw),
            'cta'                => fn() => self::generateCTA($sw, $mixed, $ctx, $tw),
            'referral_story'     => fn() => self::generateReferralStory($sw, $mixed, $ctx, $tw),
            'carousel_text'      => fn() => self::generateCarousel($sw, $mixed, $ctx, $tw),
            'poster_content'     => fn() => self::generatePosterContent($sw, $mixed, $ctx, $tw),
            'short_ad'           => fn() => self::generateShortAd($sw, $mixed, $ctx, $tw),
            'long_ad'            => fn() => self::generateLongAd($sw, $mixed, $ctx, $tw),
            'hook'               => fn() => self::generateHook($sw, $mixed, $ctx, $tw),
            'follow_up_message'  => fn() => self::generateFollowUp($sw, $mixed, $ctx, $tw),
            'customer_reply'     => fn() => self::generateCustomerReply($sw, $mixed, $ctx, $tw),
            'objection_handling' => fn() => self::generateObjectionHandling($sw, $mixed, $ctx, $tw),
        ];

        $gen = $generators[$contentType] ?? null;
        if (!$gen) {
            $s = self::pickSentence($sw, $mixed, $ctx);
            return ['success' => true, 'content' => $s . "\n📲 $refLink"];
        }

        $content = $gen();

        if (!empty($customPrompt)) {
            $content .= "\n\n---\n[Note: $customPrompt]";
        }

        return ['success' => true, 'content' => $content];
    }

    public static function getHistory(int $userId, int $page = 1, int $perPage = 20): array {
        $offset = ($page - 1) * $perPage;
        $total = Database::count('ai_content_history', 'user_id = ?', [$userId]);

        $items = Database::fetchAll(
            "SELECT * FROM ai_content_history WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$userId, $perPage, $offset]
        );

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => ceil($total / $perPage),
        ];
    }

    public static function trackShare(int $userId, string $contentType, string $platform): int {
        $existing = Database::fetchOne(
            "SELECT id, click_count FROM share_tracking WHERE user_id = ? AND content_type = ? AND share_platform = ? AND DATE(created_at) = CURDATE()",
            [$userId, $contentType, $platform]
        );

        if ($existing) {
            Database::update('share_tracking', [
                'click_count' => (int) $existing['click_count'] + 1,
            ], 'id = ?', [$existing['id']]);
            return $existing['id'];
        }

        return Database::insert('share_tracking', [
            'user_id'       => $userId,
            'content_type'  => $contentType,
            'share_platform' => $platform,
            'click_count'   => 1,
        ]);
    }

    public static function getShareStats(int $userId): array {
        $totalShares = Database::fetchOne(
            "SELECT COALESCE(SUM(click_count), 0) as total FROM share_tracking WHERE user_id = ?",
            [$userId]
        )['total'] ?? 0;

        $platformBreakdown = Database::fetchAll(
            "SELECT share_platform, SUM(click_count) as clicks, COUNT(*) as shares
             FROM share_tracking WHERE user_id = ?
             GROUP BY share_platform ORDER BY clicks DESC",
            [$userId]
        );

        $dailyReferrals = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM users WHERE referred_by = ? AND status = 'active' AND DATE(created_at) = CURDATE()",
            [$userId]
        )['cnt'] ?? 0;

        return [
            'total_shares'      => (int) $totalShares,
            'platforms'         => $platformBreakdown,
            'daily_referrals'   => (int) $dailyReferrals,
        ];
    }
}
