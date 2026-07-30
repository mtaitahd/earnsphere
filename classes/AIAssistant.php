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
        'youtube_script',
        'voiceover_script',
        'cta',
        'referral_story',
        'success_story',
        'carousel_text',
        'poster_content',
        'short_ad',
        'long_ad',
        'hook',
        'follow_up_message',
        'customer_reply',
        'objection_handling',
        'seo_title',
        'seo_description',
        'landing_page_copy',
        'homepage_hero',
        'referral_landing_copy',
        'faq',
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
            'whatsapp_message'     => 'WhatsApp Message',
            'facebook_post'        => 'Facebook Post',
            'instagram_caption'    => 'Instagram Caption',
            'tiktok_caption'       => 'TikTok Caption',
            'telegram_message'     => 'Telegram Message',
            'twitter_post'         => 'Twitter Post',
            'blog_article'         => 'Blog Article',
            'referral_sms'         => 'Referral SMS',
            'referral_email'       => 'Referral Email',
            'marketing_slogan'     => 'Marketing Slogan',
            'catchy_headline'      => 'Catchy Headline',
            'video_script'         => 'Video Script',
            'youtube_script'       => 'YouTube Script',
            'voiceover_script'     => 'Voice-over Script',
            'cta'                  => 'Call To Action',
            'referral_story'       => 'Referral Story',
            'success_story'        => 'Success Story',
            'carousel_text'        => 'Carousel Text',
            'poster_content'       => 'Poster Content',
            'short_ad'             => 'Short Ad',
            'long_ad'              => 'Long Ad',
            'hook'                 => 'Hook',
            'follow_up_message'    => 'Follow-up Message',
            'customer_reply'       => 'Customer Reply',
            'objection_handling'   => 'Objection Handling',
            'seo_title'            => 'SEO Title',
            'seo_description'      => 'SEO Description',
            'landing_page_copy'    => 'Landing Page Copy',
            'homepage_hero'        => 'Homepage Hero Text',
            'referral_landing_copy' => 'Referral Landing Copy',
            'faq'                  => 'FAQ',
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
            'whatsapp_message' => 'Friendly conversational tone. Max 300 chars. Use emojis naturally. Personal and warm.',
            'facebook_post' => 'Long engaging posts (100-300 words). Storytelling format. Add emojis. End with question or CTA. Use hashtags.',
            'instagram_caption' => 'Short captions. Engaging first line. 50-150 words. 5-10 relevant hashtags. Emojis. CTA.',
            'tiktok_caption' => 'Super short. Powerful hook in first 2 words. Max 100 chars. Trending phrases.',
            'telegram_message' => 'Medium length. Clear structure. Bullet points for benefits. Link + emojis.',
            'twitter_post' => 'Very concise. Max 280 chars. 1-3 hashtags. Every word counts.',
            'blog_article' => 'Complete article 300-500 words. Title, intro, body (3-5 key points), conclusion, CTA.',
            'referral_sms' => 'Very short. Max 160 chars. Clear offer. Name + link. No fluff.',
            'referral_email' => 'Full email: subject line, greeting, body, benefits, CTA, signature. 200-400 words.',
            'marketing_slogan' => 'One short memorable phrase. 5-10 words. Brand-focused. Easy to remember.',
            'catchy_headline' => 'Attention-grabbing. 8-15 words. Use power words. Create curiosity. AIDA framework.',
            'video_script' => '30-60 second video script. Scene directions, dialogue, timing. Hook → Body → CTA.',
            'youtube_script' => '8-15 minute YouTube script. Intro hook, main content, outro with subscribe CTA. Scene-by-scene.',
            'voiceover_script' => '30-60 second voice-over. Conversational. Pacing cues. Background music suggestions.',
            'cta' => 'Short persuasive call to action. 5-15 words. Create urgency. Tell exactly what to do. Scarcity principle.',
            'referral_story' => 'Brief story. 100-200 words. Problem → Solution → Result structure. Emotional and relatable.',
            'success_story' => 'Real success story format. Before → After → How. Include specific numbers. Social proof.',
            'carousel_text' => 'Multi-slide format. 5-7 slides. Each: headline + 1-2 sentences. Sequential flow with CTA on last slide.',
            'poster_content' => 'Poster layout: headline, subheadline, benefits, commission table, registration fee, CTA, footer, color suggestions, layout suggestions, icons, illustration ideas.',
            'short_ad' => 'Short ad. 30-50 words. Hook, problem, solution, CTA. PAS framework. Persuasive.',
            'long_ad' => 'Long-form ad. 200-400 words. Story-driven. Features, benefits, social proof, urgency, CTA.',
            'hook' => 'Super strong opening. 1-3 sentences. Must stop scroll. Use curiosity gap or emotional trigger.',
            'follow_up_message' => 'Friendly follow-up. 50-100 words. Reference previous conversation. Add value. Gentle CTA.',
            'customer_reply' => 'Helpful response. 30-80 words. Address concern. Provide solution. Be empathetic and professional.',
            'objection_handling' => 'Address objection. 50-100 words. Validate concern. Reframe. Provide evidence. Close confidently.',
            'seo_title' => 'SEO-optimized title. 50-60 chars. Include primary keyword. Click-worthy. Brand mention.',
            'seo_description' => 'SEO meta description. 150-160 chars. Include keyword. Compelling summary. CTA.',
            'landing_page_copy' => 'Full landing page copy. Hero section, features, benefits, social proof, FAQ, CTA. Conversion-focused.',
            'homepage_hero' => 'Hero section copy. Headline, subheadline, primary CTA. Value proposition in 5 seconds.',
            'referral_landing_copy' => 'Referral landing page. Explain program, commissions, steps to join, testimonial, CTA.',
            'faq' => 'FAQ format. 5-10 questions with answers. Address common objections. Clear and concise.',
        ];
        return $guidelines[$contentType] ?? 'Write clear, engaging content appropriate for the platform.';
    }

    public static function getImagePrompt(array $context): string {
        $contentType = $context['content_type'] ?? 'poster_content';
        $tone = $context['tone'] ?? 'professional';

        $styleMap = [
            'professional' => 'clean, minimalist, corporate style, premium fintech look',
            'funny' => 'colorful, playful, cartoon-style, energetic, vibrant',
            'luxury' => 'elegant, gold accents, premium dark background, exclusive feel',
            'business' => 'modern, sleek, data-driven visuals, professional charts',
            'youth' => 'vibrant, energetic, social media style, trendy, bold',
            'corporate' => 'formal, structured, brand-focused, institutional trust',
            'motivational' => 'dramatic lighting, inspiring, cinematic, sunrise metaphor',
            'simple_swahili' => 'warm, community-focused, friendly, family-oriented',
            'english' => 'clean, universal, modern, minimalist',
            'mixed_swahili_english' => 'colorful East African vibe, modern urban, cultural fusion',
        ];

        $style = $styleMap[$tone] ?? 'modern, professional fintech style';

        $prompt = "Create a premium marketing image for EarnSphere, a Tanzanian referral commission platform. ";
        $prompt .= "Style: $style. ";
        $prompt .= "Lighting: Soft, professional, well-lit, golden hour quality. ";
        $prompt .= "Typography: Bold modern sans-serif headlines, clean readable body text, mobile-first sizing. ";
        $prompt .= "Color Palette: Deep purple (#72578B) as primary, gold (#D4A843) as accent, white text, soft gray backgrounds. ";
        $prompt .= "Composition: Clean layout with ample white space, balanced elements, visual hierarchy. ";
        $prompt .= "Icons: Modern line icons representing referrals, network growth, earnings, mobile money, connections. ";
        $prompt .= "Premium Style: Looks like leading fintech marketing material from a top African company. ";
        $prompt .= "Financial Theme: Growth charts, connected nodes, handshake, phone with money, people network. ";
        $prompt .= "Modern UI: Mobile-first design, card-based layout, button-style CTA. ";
        $prompt .= "Overall: Trustworthy, aspirational, modern African financial technology. ";
        $prompt .= "The image must be suitable for: $contentType. ";
        $prompt .= "Suitable for AI image tools: ChatGPT Images, Leonardo AI, Midjourney, Flux, Canva AI, Ideogram.";

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
        $commL1 = (int) app_setting('commission_l1', 2500);
        $commL2 = (int) app_setting('commission_l2', 1500);
        $commL3 = (int) app_setting('commission_l3', 1000);
        $refLink = getReferralLink($user['referral_code']);
        $userName = $user['full_name'];
        $refCode = $user['referral_code'];

        $generated = self::generateLocalFallback($contentType, $tone, $language, $userName, $refCode, $regFee, $commL1, $siteName, $refLink, $customPrompt);

        if (!$generated['success']) {
            return $generated;
        }

        $content = $generated['content'];
        $sw = in_array($tone, ['simple_swahili', 'mixed_swahili_english']);
        $mixed = $tone === 'mixed_swahili_english';

        $structured = self::buildStructuredOutput($content, $contentType, $tone, $sw, $mixed, $commL1, $regFee, $siteName, $refLink, $refCode);

        $contentId = Database::insert('ai_content_history', [
            'user_id'          => $userId,
            'content_type'     => $contentType,
            'tone'             => $tone,
            'language'         => $language,
            'prompt_input'     => $customPrompt ?: null,
            'generated_content' => $structured,
            'word_count'       => str_word_count($structured),
            'character_count'  => strlen($structured),
            'ip_address'       => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return [
            'success'  => true,
            'content'  => $structured,
            'main_content' => $content,
            'id'       => $contentId,
            'type'     => $contentType,
            'tone'     => $tone,
            'rate_limit' => [
                'remaining' => $rateLimit['remaining'] - 1,
                'limit'     => $rateLimit['limit'],
            ],
        ];
    }

    private static function buildStructuredOutput(string $mainContent, string $contentType, string $tone, bool $sw, bool $mixed, int $commL1, int $regFee, string $siteName, string $refLink, string $refCode): string {
        $engagement = rand(72, 98);
        $audiences = $sw
            ? ['Wanafunzi', 'Watafuta Kazi', 'Wafanyakazi', 'Wajasiriamali', 'Vijana', 'Wazazi', 'Wenye Ndoto Ya Mapato Ya Ziada']
            : ['Students', 'Job Seekers', 'Employees', 'Business Owners', 'Youth', 'Parents', 'Side Hustlers', 'Anyone Wanting Extra Income'];
        $audience = self::pick($audiences);
        $platformRec = self::pick(['Facebook', 'Instagram', 'TikTok', 'WhatsApp', 'Telegram']);

        $timeMap = [
            'morning' => $sw ? 'Asubuhi (6:00-8:00)' : 'Morning (6:00-8:00 AM)',
            'lunch' => $sw ? 'Mchana (12:00-14:00)' : 'Lunch (12:00-2:00 PM)',
            'evening' => $sw ? 'Jioni (18:00-21:00)' : 'Evening (6:00-9:00 PM)',
            'weekend' => $sw ? 'Wikiendi asubuhi' : 'Weekend Mornings',
        ];
        $bestTime = self::pick($timeMap);

        $tips = $sw
            ? [
                'Tumia picha inayovutia',
                'Anza na swali la kuvutia',
                'Elezea faida kwa ufupi',
                'Weka link wazi',
                'Fuata ujumbe baada ya siku 1-2',
                'Waambie marafiki wako personally',
                'Tumia video short kuelezea',
            ]
            : [
                'Use an attention-grabbing image',
                'Start with a hook question',
                'Focus on benefits, not features',
                'Make the CTA clear and urgent',
                'Follow up within 24-48 hours',
                'Message friends personally, dont just post',
                'Use short video to explain',
                'Add social proof (testimonials)',
            ];
        $tip = self::pick($tips);

        $hashtags = $sw
            ? ['#EarnSphere', '#Kipato', '#Mapato', '#Tanzania', '#Fursa', '#Biashara', '#Mtandao', '#Pesa', '#KipatoChaZiada', '#FursaTanzania']
            : ['#EarnSphere', '#ExtraIncome', '#Tanzania', '#SideHustle', '#PassiveIncome', '#ReferralProgram', '#MakeMoneyTZ', '#FinancialFreedom', '#WorkFromHome', '#AfricanInnovation'];
        shuffle($hashtags);
        $selectedTags = array_slice($hashtags, 0, rand(3, 6));

        $title = $sw
            ? self::pick(['Mapato Ya Ziada Ya Leo!', 'Fursa Yako Ya Kipato!', 'Anza Kupata TZS ' . self::number($commL1) . ' Kwa Kila Mwaliko!', 'Njia Rahisi Ya Mapato!'])
            : self::pick(['Your Extra Income Starts Now!', 'Earn TZS ' . self::number($commL1) . ' Per Referral!', 'The Easiest Way To Earn!', 'Turn TZS ' . self::number($regFee) . ' Into Unlimited Income!']);

        $cta = $sw
            ? "👉 Bonyeza link: $refLink\n📱 Anza leo na ubadili maisha yako!"
            : "👉 Click here: $refLink\n📱 Start today and change your life!";

        return "$title\n\n" .
               str_repeat("=", 40) . "\n\n" .
               "$mainContent\n\n" .
               str_repeat("=", 40) . "\n\n" .
               "📢 \"CTA\": $cta\n\n" .
               "🏷️ \"Hashtags\": " . implode(' ', $selectedTags) . "\n\n" .
               "⏰ \"Best Time to Post\": $bestTime\n\n" .
               "🎯 \"Target Audience\": $audience\n\n" .
               "📱 \"Platform Recommendation\": $platformRec\n\n" .
               "📊 \"Estimated Engagement Score\": $engagement/100\n\n" .
               "💡 \"Conversion Tip\": $tip";
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
        $combo = rand(300, 800);
        $weekly = $commL1 * 5;
        $monthly = $commL1 * 20;
        $yearly = $monthly * 12;
        $potential = $commL1 * 10;
        return compact('regFee', 'commL1', 'commL2', 'commL3', 'siteName', 'link', 'combo', 'weekly', 'monthly', 'yearly', 'potential');
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
        $c3 = self::number($ctx['commL3']);
        $co = self::number($ctx['combo']);
        $w = self::number($ctx['weekly']);
        $m = self::number($ctx['monthly']);
        $y = self::number($ctx['yearly']);
        $p = self::number($ctx['potential']);
        $site = $ctx['siteName'];
        $link = $ctx['link'];

        return [
            "Join $site today for just TZS $f and earn TZS $c1 per Level 1 referral!",
            "Only TZS $f to start. Earn TZS $c1 per referral plus TZS $c2 at Level 2 and TZS $c3 at Level 3.",
            "Turn TZS $f into TZS $m+ monthly. Refer friends, earn up to TZS $c1 per person.",
            "Make TZS $c1 per referral with $site. One-time fee of just TZS $f.",
            "Your earning potential: TZS $c1 per person × your network = unlimited income.",
            "3 levels of commissions: TZS $c1 → TZS $c2 → TZS $c3. Your entire network works for you.",
            "TZS $f unlocks a TZS $p+ earning potential. That's $site.",
            "Level 1: TZS $c1, Level 2: TZS $c2, Level 3: TZS $c3. Every referral counts.",
            "Imagine earning TZS $w this week just by sharing your link. That's $site.",
            "Refer 10 friends → TZS " . self::number(10 * $ctx['commL1']) . "  Refer 50 friends → TZS " . self::number(50 * $ctx['commL1']) . ". Start with TZS $f.",
        ];
    }

    private static function swSentences(array $ctx): array {
        $f = self::number($ctx['regFee']);
        $c1 = self::number($ctx['commL1']);
        $c2 = self::number($ctx['commL2']);
        $c3 = self::number($ctx['commL3']);
        $co = self::number($ctx['combo']);
        $w = self::number($ctx['weekly']);
        $m = self::number($ctx['monthly']);
        $y = self::number($ctx['yearly']);
        $p = self::number($ctx['potential']);
        $site = $ctx['siteName'];
        $link = $ctx['link'];

        return [
            "Jiunge na $site kwa TZS $f tu na pata TZS $c1 kwa kila mwaliko wa ngazi ya 1!",
            "TZS $f pekee kuanza. Pata TZS $c1 ngazi 1, TZS $c2 ngazi 2, TZS $c3 ngazi 3.",
            "Badilisha TZS $f hadi TZS $m+ kwa mwezi. Ngazi 3 za commission: $c1 → $c2 → $c3.",
            "Pata TZS $c1 kwa kila mwaliko wa moja kwa moja. Pia TZS $c2 na TZS $c3 kwa wanaokuja baada yako.",
            "Uwezo wako: TZS $c1 × mtandao wako mzima = mapato yasiyo na mwisho.",
            "$site inakupa $c1 ngazi ya 1, $c2 ngazi ya 2, $c3 ngazi ya 3. Watu wako wote wanakufanyia kazi!",
            "Fikiria kupata TZS $w wiki hii, TZS $m mwezi huu, TZS $y mwaka huu. Yote kuanzia TZS $f!",
            "Kila mtu unayemwalika $site anakuletea TZS $c1. Na watu wanaokuja kupitia kwao wanakuletea TZS $c2 na $c3.",
            "TZS $f tu kufungua mlango wa mapato ya TZS $p+ kwa uwezo wako.",
            "Kompyuta yako ni kiwanda chako cha pesa. $site inakupa zana. Anza leo kwa TZS $f.",
        ];
    }

    private static function mixedSentences(array $ctx): array {
        $f = self::number($ctx['regFee']);
        $c1 = self::number($ctx['commL1']);
        $c2 = self::number($ctx['commL2']);
        $c3 = self::number($ctx['commL3']);
        $co = self::number($ctx['combo']);
        $w = self::number($ctx['weekly']);
        $m = self::number($ctx['monthly']);
        $site = $ctx['siteName'];
        $link = $ctx['link'];

        return [
            "Jiunge na $site kwa TZS $f tu. Start earning TZS $c1 per referral!",
            "TZS $f one-time fee. After that, unapata TZS $c1 kwa kila mtu.",
            "Anza na TZS $f, earn TZS $c1 per referral. Simple right?",
            "Una withdraw M-Pesa instantly. That's the beauty of $site.",
            "Pata TZS $c1 per referral, level 2 inakupa TZS $c2 extra. Hii ni fursa!",
            "3 level commission: $c1 Level 1, $c2 Level 2, $c3 Level 3. Your network inakufanyia kazi!",
            "TZS $w per week inawezekana. TZS $m per month. Anza na TZS $f tu!",
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
        $link = $ctx['link'];
        $greet = $sw ? self::pick(['Habari! 👋', 'Mambo! 😊', 'Niaje! ✌️', 'Sema! 👋']) : self::pick(["Hey! 👋", "Hello! 👋", "Hi there! 😊", "What's up! ✌️"]);
        $close = $sw ? self::pick(["📲 $link", "👇 $link", "👉 $link", "🔗 $link"]) : self::pick(["📲 $link", "👇 $link", "👉 $link", "🔗 $link"]);
        return "$greet\n$s\n\n$e $a opportunity! Withdraw to M-Pesa/Airtel instantly.\n\n$close";
    }

    private static function generateFacebook(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s1 = self::pickSentence($sw, $mixed, $ctx);
        $s2 = self::pickSentence($sw, $mixed, $ctx);
        $e = self::pick($tw['emoji']);
        $link = $ctx['link'];
        $tag1 = $sw ? '#EarnSphere' : '#EarnSphere';
        $tags = $sw
            ? self::pick([['#Kipato', '#Mapato', '#Tanzania', '#Fursa'], ['#Biashara', '#Mtandao', '#KipatoChaZiada'], ['#Pesa', '#FursaTanzania', '#MapatoYaZiada']])
            : self::pick([['#ExtraIncome', '#WorkFromHome', '#Tanzania', '#PassiveIncome'], ['#SideHustle', '#ReferralProgram', '#MakeMoneyTZ'], ['#FinancialFreedom', '#PassiveIncome', '#TanzaniaBusiness']]);
        $head = $sw ? self::pick(["🚀 \"MAPATO YA ZIADA YANAWEZEKANA!\"", "💰 \"FURSA YAKO YA KIPATO IKO HAPA!\"", "🔥 \"ANZA KUPATA MAPATO LEO!\""]) : self::pick(["🚀 \"EARN EXTRA INCOME FROM HOME!\"", "💰 \"YOUR INCOME OPPORTUNITY IS HERE!\"", "🔥 \"START EARNING TODAY!\""]);
        $q = $sw ? "Je, uko tayari kubadilisha maisha yako?" : "Are you ready to change your life?";
        return "$head\n\n$q\n\n$s1\n\n$s2\n\n$e \"Withdraw M-Pesa / Airtel Money\"\n\n📲 $link\n\n" . implode(' ', array_map(fn($t) => "#$t", $tags));
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
        if (mb_strlen($s) > 100) $s = mb_substr($s, 0, 97) . '...';
        $hook = $sw
            ? self::pick(["Hii ni fursa yako! 🚀", "Usikose hii! 🔥", "Njoo tuoneshwe! 💰"])
            : self::pick(["Don't scroll past this! 🚀", "This is your sign! 🔥", "Watch this! 💰"]);
        return "$hook\n$s";
    }

    private static function generateTelegram(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s1 = self::pickSentence($sw, $mixed, $ctx);
        $s2 = self::pickSentence($sw, $mixed, $ctx);
        $e = self::pick($tw['emoji']);
        $link = $ctx['link'];
        $head = $sw ? "🚀 \"Fursa ya Kipato cha Ziada!\"" : "🚀 \"Extra Income Opportunity!\"";
        $close = $sw ? "@EarnSphere - Fursa Yako ya Mapato!" : "@EarnSphere - Your Income Opportunity!";
        return "$head\n\n$s1\n\n$s2\n\n$e Withdraw via M-Pesa / Airtel Money\n\n🔗 $link\n\n$close";
    }

    private static function generateTwitter(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s = self::pickSentence($sw, $mixed, $ctx);
        $e = self::pick($tw['emoji']);
        $link = $ctx['link'];
        $content = $sw
            ? "$e $s $e\n📲 $link\n#EarnSphere #Tanzania"
            : "$e $s $e\n📲 $link\n#EarnSphere #Tanzania";
        if (mb_strlen($content) > 280) $content = mb_substr($content, 0, 277) . '...';
        return $content;
    }

    private static function generateSMS(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s = self::pickSentence($sw, $mixed, $ctx);
        $link = $ctx['link'];
        $content = $sw
            ? "$s 📲 $link"
            : "$s 📲 $link";
        if (mb_strlen($content) > 160) $content = mb_substr($content, 0, 157) . '...';
        return $content;
    }

    private static function generateEmail(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s1 = self::pickSentence($sw, $mixed, $ctx);
        $s2 = self::pickSentence($sw, $mixed, $ctx);
        $s3 = self::pickSentence($sw, $mixed, $ctx);
        $greet = $sw ? "Habari rafiki!" : "Hello friend!";
        $link = $ctx['link'];
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
        $link = $ctx['link'];
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
        return "\"$t\"\n\n$p1\n\n$s\n\n$p2\n\n$p3\n\n📲 $link";
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
        $link = $ctx['link'];
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
        $link = $ctx['link'];
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
        $link = $ctx['link'];
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
        $link = $ctx['link'];
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
        $link = $ctx['link'];
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
        return "HEADLINE: $h\nSUBHEADLINE: $sh\n\nBENEFITS:\n$b\n\nCTA: $cta\n\nHASHTAGS: $tags\n\nCOLORS: Purple (#72578B), Gold (#D4A843)\nLAYOUT: Mobile-friendly vertical poster";
    }

    private static function generateShortAd(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $f = self::number($ctx['regFee']);
        $c1 = self::number($ctx['commL1']);
        $site = $ctx['siteName'];
        $e = self::pick($tw['emoji']);
        $link = $ctx['link'];
        $s = self::pickSentence($sw, $mixed, $ctx);
        if ($sw) {
            return "$e \"MAPATO YAKO HAPA!\"\n\n$site inakupa TZS $c1 kwa kila mtu unayemwalika. Malipo ya mara moja TZS $f.\n\n✅ Halali\n✅ Haraka\n✅ Hakuna vikwazo\n\n👉 $link";
        }
        return "$e \"EXTRA INCOME IS HERE!\"\n\n$site gives you TZS $c1 for every person you invite. One-time payment TZS $f.\n\n✅ Legitimate\n✅ Fast\n✅ No hidden fees\n\n👉 $link";
    }

    private static function generateLongAd(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $s1 = self::pickSentence($sw, $mixed, $ctx);
        $s2 = self::pickSentence($sw, $mixed, $ctx);
        $s3 = self::pickSentence($sw, $mixed, $ctx);
        $site = $ctx['siteName'];
        $e = self::pick($tw['emoji']);
        $link = $ctx['link'];
        if ($sw) {
            $h = "FURSA YA MAPATO YA ZIADA";
            $close = "Usikose nafasi hii. Tuma ujumbe au bonyeza link leo!";
        } else {
            $h = "EXTRA INCOME OPPORTUNITY";
            $close = "Don't miss this chance. Send a message or click the link today!";
        }
        return "\"$h\"\n\n$s1\n\n$s2\n\n$s3\n\n$e $close\n\n📲 $link";
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

    private static function generateYouTubeScript(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $f = self::number($ctx['regFee']);
        $w = self::number($ctx['weekly']);
        $link = $ctx['link'];
        $e = self::pick($tw['emoji']);
        $intro = $sw
            ? "Karibu kwenye video hii! Leo nitakuonyesha jinsi ya kupata TZS $c1 kwa kushare link tu."
            : "Welcome to this video! Today I'll show you how to earn TZS $c1 just by sharing a link.";
        $body1 = $sw
            ? "$site ni jukwaa la Tanzania linalokulipa kwa kualika marafiki. Kwa malipo ya mara moja ya TZS $f tu, unaanza kupata commission kwa kila mtu."
            : "$site is a Tanzanian platform that pays you for inviting friends. For a one-time fee of TZS $f, you earn commissions on every person.";
        $body2 = $sw
            ? "Ngazi 1: TZS $c1 kwa kila mwaliko. Ngazi 2: TZS " . self::number($ctx['commL2']) . ". Ngazi 3: TZS " . self::number($ctx['commL3']) . ". Watu wanaokuja kupitia kwao wanakuletea wewe commission!"
            : "Level 1: TZS $c1 per direct referral. Level 2: TZS " . self::number($ctx['commL2']) . ". Level 3: TZS " . self::number($ctx['commL3']) . ". You earn from your entire network!";
        $cta = $sw ? "Bonyeza link kwenye description. Jiunge. Anza kupata leo!" : "Click the link in description. Join. Start earning today!";
        $outro = $sw ? "Kumboka: Withdraw M-Pesa/Airtel. Hakuna vikwazo." : "Remember: Withdraw to M-Pesa/Airtel. No restrictions.";
        return "[INTRO HOOK]\n$intro $e\n\n[SCENE 1 - WHAT IS $site]\n$body1\n\n[SCENE 2 - COMMISSION STRUCTURE]\n$body2\n\n[SCENE 3 - PROOF / TESTIMONIAL]\nPeople are earning TZS $w per week from home!\n\n[OUTRO - CTA]\n$cta\n\n[END SCREEN]\n$outro\n\n📲 $link";
    }

    private static function generateSuccessStory(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $w = self::number($ctx['weekly']);
        $m = self::number($ctx['monthly']);
        $y = self::number($ctx['yearly']);
        $link = $ctx['link'];
        $names = $sw ? ['Juma', 'Asha', 'Baraka', 'Neema', 'Salum', 'Mwajuma', 'Hamisi', 'Zainabu'] : ['John', 'Sarah', 'Peter', 'Grace', 'David', 'Mary', 'Kevin', 'Esther'];
        $name = self::pick($names);
        $weeks = rand(2, 8);
        $friendsCount = rand(5, 15);
        $beforeJob = $sw
            ? self::pick(['alikuwa akitafuta kazi', 'alikuwa mwanafunzi', 'alikuwa na kazi lakini akitaka zaidi', 'alikuwa mama wa nyumbani'])
            : self::pick(['was job hunting', 'was a student', 'had a job but wanted more', 'was a stay-at-home parent']);
        $e = self::pick($tw['emoji']);
        if ($sw) {
            return "\"Hadithi Ya Mafanikio: $name\" $e\n\n" .
                   "\"BEFORE:\"\n$name $beforeJob. Alitaka kuanza biashara lakini hakuwa na mtaji.\n\n" .
                   "\"THE TURNING POINT:\"\nAligundua $site. Kwa TZS " . self::number($ctx['regFee']) . " tu, alijiunga na kuanza kushare link yake na marafiki.\n\n" .
                   "\"THE RESULT ($weeks weeks later):\"\n" .
                   "✅ Alialika watu $friendsCount ambao walijiunga\n" .
                   "✅ Anapata TZS $w kwa wiki\n" .
                   "✅ TZS $m kwa mwezi\n" .
                   "✅ Projected: TZS $y kwa mwaka\n\n" .
                   "\"HIS ADVICE:\"\n\"Usiogope kuanza. Kitu unachohitaji ni TZS " . self::number($ctx['regFee']) . " tu na nia ya kushare. Mapato yanakuja!\"\n\n📲 $link\n#EarnSphere #SuccessStory";
        }
        return "\"Success Story: $name\" $e\n\n" .
               "\"BEFORE:\"\n$name $beforeJob. Wanted to start earning but had no capital.\n\n" .
               "\"THE TURNING POINT:\"\nDiscovered $site. For just TZS " . self::number($ctx['regFee']) . ", joined and started sharing the link.\n\n" .
               "\"THE RESULT ($weeks weeks later):\"\n" .
               "✅ Invited $friendsCount people who joined\n" .
               "✅ Now earning TZS $w per week\n" .
               "✅ TZS $m per month\n" .
               "✅ Projected: TZS $y per year\n\n" .
               "\"ADVICE:\"\n\"Don't be afraid to start. All you need is TZS " . self::number($ctx['regFee']) . " and the willingness to share. The income will follow!\"\n\n📲 $link\n#EarnSphere #SuccessStory";
    }

    private static function generateSeoTitle(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $m = self::number($ctx['monthly']);
        if ($sw) {
            return self::pick([
                "Pata Mapato Ya Ziada Tanzania - $site inalipa TZS $c1 kwa kila mwaliko",
                "Jinsi Ya Kupata TZS $m Kwa Mwezi Kutumia $site - Hakuna Mtaji",
                "$site Tanzania: Njia Rahisi Ya Kupata Kipato Cha Ziada Online",
                "Fursa Ya Kazi Za Mtandaoni Tanzania - $site Inakulipa TZS $c1 Kwa Rufaa",
                "Njia Bora Ya Kupata Pesa Mtandaoni Tanzania 2026 - $site",
            ]);
        }
        return self::pick([
            "Earn Extra Income Tanzania - $site Pays TZS $c1 Per Referral",
            "Make TZS $m Monthly with $site - No Capital Required",
            "$site Tanzania: The Easiest Way to Earn Passive Income Online",
            "Online Job Opportunity Tanzania - $site Pays TZS $c1 Per Referral 2026",
            "Best Way to Make Money Online Tanzania 2026 - $site Review",
            "TZS $c1 Per Referral: Join $site and Build Your Network Income",
        ]);
    }

    private static function generateSeoDescription(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $f = self::number($ctx['regFee']);
        $m = self::number($ctx['monthly']);
        $desc = $sw
            ? "Pata mapato ya ziada kwa $site! Jiunge kwa TZS $f tu, pata TZS $c1 kwa kila mwaliko (Ngazi 1), TZS " . self::number($ctx['commL2']) . " (Ngazi 2), TZS " . self::number($ctx['commL3']) . " (Ngazi 3). Withdraw M-Pesa/Airtel. Anza leo!"
            : "Earn extra income with $site! Join for TZS $f, earn TZS $c1 per referral (Level 1), TZS " . self::number($ctx['commL2']) . " (Level 2), TZS " . self::number($ctx['commL3']) . " (Level 3). Withdraw to M-Pesa/Airtel. Start today!";
        if (mb_strlen($desc) > 160) $desc = mb_substr($desc, 0, 157) . '...';
        return $desc;
    }

    private static function generateLandingPageCopy(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $c2 = self::number($ctx['commL2']);
        $c3 = self::number($ctx['commL3']);
        $f = self::number($ctx['regFee']);
        $w = self::number($ctx['weekly']);
        $m = self::number($ctx['monthly']);
        $y = self::number($ctx['yearly']);
        $e = self::pick($tw['emoji']);
        $h = $sw ? "Pata Mapato Ya Ziada Kwa Kushare Link" : "Earn Extra Income by Sharing a Link";
        $sh = $sw ? "Jiunge na $site kwa TZS $f tu na anza kupata TZS $c1 kwa kila mwaliko" : "Join $site for just TZS $f and start earning TZS $c1 per referral";
        $feat1 = $sw ? "✅ Malipo ya mara moja TZS $f - Hakuna ada za kila mwezi" : "✅ One-time payment TZS $f - No monthly fees";
        $feat2 = $sw ? "✅ Commission ngazi 3: $c1 → $c2 → $c3" : "✅ 3-level commission: $c1 → $c2 → $c3";
        $feat3 = $sw ? "✅ Withdraw M-Pesa / Airtel Money - Papo hapo" : "✅ Instant withdrawal M-Pesa / Airtel Money";
        $feat4 = $sw ? "✅ Hakuna ujuzi wa teknolojia unaohitajika" : "✅ No technical skills required";
        $social = $sw
            ? "\"Nilijiunga wiki 2 zilizopita, nashapata TZS $w kwa wiki! Hii ni fursa halisi!\" - Juma"
            : "\"I joined 2 weeks ago, already earning TZS $w per week! This is real!\" - Peter";
        $link = $ctx['link'];
        $cta = $sw ? "ANZA SASA - Jiunge Kwa TZS $f Tu" : "START NOW - Join For Only TZS $f";
        if ($sw) {
            return "HERO SECTION\n══════════\n\"$h\"\n\n$sh\n\n[$cta]\n\n---\n\nFEATURES & BENEFITS\n══════════════════\n$feat1\n$feat2\n$feat3\n$feat4\n\n---\n\nHOW IT WORKS\n══════════════\n1. Jiunge kwa TZS $f\n2. Pata link yako ya kipekee\n3. Share na marafiki\n4. Pata TZS $c1 kwa kila mtu!\n\n---\n\nSOCIAL PROOF\n══════════════\n$social\n\n---\n\nCOMMISSION STRUCTURE\n════════════════════\nLevel 1: TZS $c1\nLevel 2: TZS $c2\nLevel 3: TZS $c3\n\n---\n\nFAQ\n════\nJe, hii ni halali? Ndiyo, $site ni jukwaa halali la Tanzania.\nJe, nahakikishaje kupata pesa? Ukialika watu, unapata commission. Rahisi.\nJe, naweza kutoa pesa wakati wowote? Ndiyo, M-Pesa/Airtel.\n\n---\n\nFINAL CTA\n══════════\n\"$e Usikose Fursa Hii! $e\"\n\n👉 $link";
        }
        return "HERO SECTION\n══════════\n\"$h\"\n\n$sh\n\n[$cta]\n\n---\n\nFEATURES & BENEFITS\n══════════════════\n$feat1\n$feat2\n$feat3\n$feat4\n\n---\n\nHOW IT WORKS\n══════════════\n1. Join for TZS $f\n2. Get your unique referral link\n3. Share with friends\n4. Earn TZS $c1 per person!\n\n---\n\nSOCIAL PROOF\n══════════════\n$social\n\n---\n\nCOMMISSION STRUCTURE\n════════════════════\nLevel 1: TZS $c1\nLevel 2: TZS $c2\nLevel 3: TZS $c3\n\n---\n\nFAQ\n════\nIs this legitimate? Yes, $site is a legitimate Tanzanian platform.\nHow do I get paid? Invite people, earn commissions. Simple.\nCan I withdraw anytime? Yes, to M-Pesa/Airtel.\n\n---\n\nFINAL CTA\n══════════\n\"$e Don't Miss This Opportunity! $e\"\n\n👉 $link";
    }

    private static function generateHomepageHero(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $link = $ctx['link'];
        $c2 = self::number($ctx['commL2']);
        $c3 = self::number($ctx['commL3']);
        $f = self::number($ctx['regFee']);
        $e = self::pick($tw['emoji']);
        $headline = $sw
            ? self::pick(["Pata Mapato Kwa Kushare Link", "Kipato Chako Cha Ziada Kiko Hapa", "Badilisha Maisha Yako Leo", "Fursa Yako Ya Kipato Cha Ziada"])
            : self::pick(["Earn Income By Sharing Links", "Your Extra Income Starts Here", "Change Your Life Today", "Your Opportunity For Extra Income"]);
        $sub = $sw
            ? "$site inakupa TZS $c1 kwa kila mtu unayemwalika. Level 2: $c2, Level 3: $c3. Withdraw M-Pesa."
            : "$site gives you TZS $c1 per referral. Level 2: $c2, Level 3: $c3. Withdraw to M-Pesa.";
        $cta = $sw ? "Jiunge Sasa - TZS $f Tu" : "Join Now - Only TZS $f";
        $support = $sw ? "Wanachama 500+ Tanzania" : "500+ Members in Tanzania";
        return "\"HERO SECTION\"\n\n\"$headline\" $e\n\n$sub\n\n[$cta]\n\n$support\n\n📲 $link";
    }

    private static function generateReferralLandingCopy(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $c2 = self::number($ctx['commL2']);
        $c3 = self::number($ctx['commL3']);
        $f = self::number($ctx['regFee']);
        $w = self::number($ctx['weekly']);
        $m = self::number($ctx['monthly']);
        $e = self::pick($tw['emoji']);
        $h = $sw ? "Unaalikwa Kujiunga Na $site" : "You're Invited To Join $site";
        $intro = $sw
            ? "Habari! Nina fursa nzuri ya kukutambulisha. $site ni jukwaa la Tanzania linalolipa wanachama wake kwa kuwakaribisha wengine."
            : "Hello! I have an amazing opportunity to introduce to you. $site is a Tanzanian platform that pays its members for inviting others.";
        $steps = $sw
            ? "1. Jiunge kwa TZS $f (malipo ya mara moja)\n2. Pata link yako mwenyewe\n3. Anza kushare na marafiki\n4. Pata TZS $c1 kwa kila mtu anayejiunga!"
            : "1. Join for TZS $f (one-time payment)\n2. Get your own referral link\n3. Start sharing with friends\n4. Earn TZS $c1 for every person who joins!";
        $comm = $sw
            ? "Ngazi 1: TZS $c1 | Ngazi 2: TZS $c2 | Ngazi 3: TZS $c3"
            : "Level 1: TZS $c1 | Level 2: TZS $c2 | Level 3: TZS $c3";
        $test = $sw
            ? "\"Nilijiunga wiki 3 zilizopita na nashapata TZS $w kwa wiki!\" - Asha"
            : "\"I joined 3 weeks ago I'm earning TZS $w per week!\" - Sarah";
        $link = $ctx['link'];
        $cta = $sw ? "Bonyeza Hapa Kujiunga" : "Click Here To Join";
        return "\"$h\" $e\n\n$intro\n\n---\n\n\"HOW IT WORKS:\"\n$steps\n\n---\n\n\"COMMISSION STRUCTURE:\"\n$comm\n\n---\n\n\"TESTIMONIAL:\"\n$test\n\n---\n\n\"MINIMUM WITHDRAWAL:\" TZS 5,000 to M-Pesa / Airtel Money\n\n---\n\n\"👉 $cta\": $link\n\n$e Join today and start building your income!";
    }

    private static function generateFAQ(bool $sw, bool $mixed, array $ctx, array $tw): string {
        $site = $ctx['siteName'];
        $c1 = self::number($ctx['commL1']);
        $c2 = self::number($ctx['commL2']);
        $c3 = self::number($ctx['commL3']);
        $f = self::number($ctx['regFee']);
        $link = $ctx['link'];
        if ($sw) {
            return "\"Maswali Ya Kawaida - $site\"\n\n" .
                   "\"Swali: $site ni nini?\"\nJibu: $site ni jukwaa la Tanzania linalokulipa kwa kuwalika marafiki kujiunga. Unapata commission kwa kila mtu unayemleta kwenye mfumo.\n\n" .
                   "\"Swali: Nahitaji pesa ngapi kuanza?\"\nJibu: TZS $f tu - malipo ya mara moja. Hakuna ada za kila mwezi wala ada zilizofichwa.\n\n" .
                   "\"Swali: Napata kiasi gani kwa kila mtu?\"\nJibu: Ngazi 1: TZS $c1, Ngazi 2: TZS $c2, Ngazi 3: TZS $c3. Unapata commission kutoka kwa watu wote unaowalika na wanaokuja kupitia kwao.\n\n" .
                   "\"Swali: Je, hii ni halali?\"\nJibu: Ndiyo! $site ni jukwaa halali la Tanzania linalofanya kazi kwa kufuata sheria za biashara za Tanzania.\n\n" .
                   "\"Swali: Naondoa pesa vipi?\"\nJibu: Withdraw moja kwa moja M-Pesa au Airtel Money. Kiasi cha chini cha withdrawal ni TZS 5,000.\n\n" .
                   "\"Swali: Nahitaji ujuzi gani?\"\nJibu: Hakuna ujuzi maalum unaohitajika. Unachohitaji ni simu na nia ya kushare link yako.\n\n" .
                   "\"Swali: Je, ninaweza kufanya hii nikiwa na kazi?\"\nJibu: Ndiyo! Watu wengi wanafanya $site kama kazi ya ziada (side hustle). Unatumia muda wako wa ziada tu.\n\n" .
                   "\"Swali: Naanza vipi?\"\nJibu: Bonyeza link hapa chini, jiunge, na utapata maelezo yote! 👉 $link";
        }
        return "\"Frequently Asked Questions - $site\"\n\n" .
               "\"Q: What is $site?\"\nA: $site is a Tanzanian referral platform that pays you for inviting friends to join. You earn commissions for every person you bring to the platform.\n\n" .
               "\"Q: How much do I need to start?\"\nA: Just TZS $f - a one-time payment. No monthly fees or hidden charges.\n\n" .
               "\"Q: How much do I earn per person?\"\nA: Level 1: TZS $c1, Level 2: TZS $c2, Level 3: TZS $c3. You earn from everyone you invite PLUS those they invite.\n\n" .
               "\"Q: Is this legitimate?\"\nA: Yes! $site is a legitimate Tanzanian platform operating under Tanzanian business regulations.\n\n" .
               "\"Q: How do I withdraw?\"\nA: Direct withdrawal to M-Pesa or Airtel Money. Minimum withdrawal is TZS 5,000.\n\n" .
               "\"Q: What skills do I need?\"\nA: No special skills required. Just a phone and willingness to share your referral link.\n\n" .
               "\"Q: Can I do this while working?\"\nA: Yes! Most members do $site as a side hustle using their spare time.\n\n" .
               "\"Q: How do I start?\"\nA: Click the link below, join, and you'll get all the details! 👉 $link";
    }

    private static function generateLocalFallback(string $contentType, string $tone, string $language, string $userName, string $refCode, int $regFee, int $commL1, string $siteName, string $refLink, string $customPrompt): array {
        $sw = in_array($tone, ['simple_swahili', 'mixed_swahili_english']);
        $mixed = $tone === 'mixed_swahili_english';
        $ctx = self::buildContext($regFee, $commL1, 1500, 1000, $siteName, $refLink);
        $tw = self::toneWords($tone);

        $generators = [
            'whatsapp_message'     => fn() => self::generateWhatsApp($sw, $mixed, $ctx, $tw),
            'facebook_post'        => fn() => self::generateFacebook($sw, $mixed, $ctx, $tw),
            'instagram_caption'    => fn() => self::generateInstagram($sw, $mixed, $ctx, $tw),
            'tiktok_caption'       => fn() => self::generateTikTok($sw, $mixed, $ctx, $tw),
            'telegram_message'     => fn() => self::generateTelegram($sw, $mixed, $ctx, $tw),
            'twitter_post'         => fn() => self::generateTwitter($sw, $mixed, $ctx, $tw),
            'blog_article'         => fn() => self::generateBlog($sw, $mixed, $ctx, $tw),
            'referral_sms'         => fn() => self::generateSMS($sw, $mixed, $ctx, $tw),
            'referral_email'       => fn() => self::generateEmail($sw, $mixed, $ctx, $tw),
            'marketing_slogan'     => fn() => self::generateSlogan($sw, $mixed, $ctx, $tw),
            'catchy_headline'      => fn() => self::generateHeadline($sw, $mixed, $ctx, $tw),
            'video_script'         => fn() => self::generateVideoScript($sw, $mixed, $ctx, $tw),
            'youtube_script'       => fn() => self::generateYouTubeScript($sw, $mixed, $ctx, $tw),
            'voiceover_script'     => fn() => self::generateVoiceover($sw, $mixed, $ctx, $tw),
            'cta'                  => fn() => self::generateCTA($sw, $mixed, $ctx, $tw),
            'referral_story'       => fn() => self::generateReferralStory($sw, $mixed, $ctx, $tw),
            'success_story'        => fn() => self::generateSuccessStory($sw, $mixed, $ctx, $tw),
            'carousel_text'        => fn() => self::generateCarousel($sw, $mixed, $ctx, $tw),
            'poster_content'       => fn() => self::generatePosterContent($sw, $mixed, $ctx, $tw),
            'short_ad'             => fn() => self::generateShortAd($sw, $mixed, $ctx, $tw),
            'long_ad'              => fn() => self::generateLongAd($sw, $mixed, $ctx, $tw),
            'hook'                 => fn() => self::generateHook($sw, $mixed, $ctx, $tw),
            'follow_up_message'    => fn() => self::generateFollowUp($sw, $mixed, $ctx, $tw),
            'customer_reply'       => fn() => self::generateCustomerReply($sw, $mixed, $ctx, $tw),
            'objection_handling'   => fn() => self::generateObjectionHandling($sw, $mixed, $ctx, $tw),
            'seo_title'            => fn() => self::generateSeoTitle($sw, $mixed, $ctx, $tw),
            'seo_description'      => fn() => self::generateSeoDescription($sw, $mixed, $ctx, $tw),
            'landing_page_copy'    => fn() => self::generateLandingPageCopy($sw, $mixed, $ctx, $tw),
            'homepage_hero'        => fn() => self::generateHomepageHero($sw, $mixed, $ctx, $tw),
            'referral_landing_copy' => fn() => self::generateReferralLandingCopy($sw, $mixed, $ctx, $tw),
            'faq'                  => fn() => self::generateFAQ($sw, $mixed, $ctx, $tw),
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
